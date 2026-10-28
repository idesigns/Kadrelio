<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\MondayBoard;
use App\Models\MondayItem;
use App\Models\User;
use App\Models\WorkflowRecord;
use Illuminate\Database\Eloquent\Factories\Sequence;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GroupPaginationTest extends TestCase
{
    public function test_live_search_returns_only_matching_groups_and_updates_the_total(): void
    {
        $company = Company::factory()->create();
        $employee = Employee::factory()->for($company)->create(['first_name' => 'Anna', 'last_name' => 'Kowalska']);
        WorkflowRecord::factory()->for($company)->for($employee)->create(['status' => 'signed']);
        WorkflowRecord::factory()->for($company)->for($employee)->create(['status' => 'terminated']);
        $url = route('companies.board', [$company, 'contracts']);

        $this->actingAs($company->owner)->withHeader('X-Board-Search', '1')->get($url.'?q=Kowalska')->assertOk()
            ->assertSee('data-total="2"', false)->assertSee('data-paginated-group="signed"', false)
            ->assertSee('data-paginated-group="terminated"', false)->assertDontSee('<html', false);
        $this->get($url.'?q=brak-dopasowania')->assertOk()->assertSee('data-total="0"', false)->assertSee('Brak wyników')->assertDontSee('Kowalska');
        $this->actingAs(User::factory()->create())->get($url.'?q=Kowalska')->assertNotFound();
    }

    public function test_monday_live_search_finds_encrypted_fields_without_returning_the_page_shell(): void
    {
        $board = MondayBoard::factory()->create();
        MondayItem::factory()->for($board, 'board')->create();
        MondayItem::factory()->for($board, 'board')->create(['group_name' => 'Umowy rozwiązane']);

        $this->actingAs($board->company->owner)->withHeader('X-Board-Search', '1')->get(route('monday.boards.show', [$board, 'q' => '44051401458']))->assertOk()
            ->assertSee('data-total="2"', false)->assertSee('Aktualne umowy')->assertSee('Umowy rozwiązane')->assertDontSee('<html', false);
    }

    #[DataProvider('workflowBoards')]
    public function test_each_workflow_group_has_its_own_pages(string $board, string $kind): void
    {
        $company = Company::factory()->create();
        $employee = Employee::factory()->for($company)->create();
        $signed = WorkflowRecord::factory()->for($company)->for($employee)->count(51)->create(['kind' => $kind, 'status' => 'signed', 'signed_at' => now()]);
        $terminated = WorkflowRecord::factory()->for($company)->for($employee)->count(51)->create(['kind' => $kind, 'status' => 'terminated', 'signed_at' => now()]);
        $signedLink = 'href="'.route('companies.records.show', [$company, $signed->first()]).'"';
        $terminatedLink = 'href="'.route('companies.records.show', [$company, $terminated->last()]).'"';
        $url = route('companies.board', [$company, $board]);

        $this->actingAs($company->owner)->get($url)->assertOk()
            ->assertSee('Elementy: 102')->assertSee('Elementy: 51')
            ->assertSee(route('companies.records.show', [$company, $signed->last()]), false)
            ->assertSee($terminatedLink, false)->assertDontSee($signedLink, false);
        $this->get($url.'?page_signed=2')->assertOk()->assertSee($signedLink, false)->assertSee($terminatedLink, false)->assertSee('51-51 z 51');
        $this->withHeader('X-Board-Group', 'signed')->get($url.'?page_signed=2')->assertOk()
            ->assertSee($signedLink, false)->assertDontSee($terminatedLink, false)->assertDontSee('<html', false)
            ->assertSee('data-current-page="2"', false);
    }

    public static function workflowBoards(): array
    {
        return [
            ['requests', 'contract'], ['contracts', 'contract'], ['amendments', 'amendment'],
            ['terminations', 'termination'], ['statements', 'contract'], ['changes', 'data_change'], ['multisport', 'multisport'],
        ];
    }

    public function test_completed_and_incomplete_employees_are_paginated_independently(): void
    {
        $company = Company::factory()->create();
        $completed = Employee::factory()->for($company)->completed()->count(51)->sequence(fn (Sequence $sequence): array => ['last_name' => sprintf('Kowalski %03d', $sequence->index)])->create();
        $waiting = Employee::factory()->for($company)->create(['last_name' => 'Zieliński oczekujący']);
        $url = route('companies.board', [$company, 'employees']);

        $this->actingAs($company->owner)->get($url)->assertOk()->assertSee($completed->first()->fullName())->assertSee($waiting->fullName())
            ->assertSee('Osoby: 52')->assertSee('Osoby: 51')->assertDontSee($completed->last()->fullName());
        $this->withHeader('X-Board-Group', 'completed')->get($url.'?page_completed=2')->assertOk()
            ->assertSee($completed->last()->fullName())->assertDontSee($waiting->fullName())->assertSee('51-51 z 51');
    }

    public function test_group_pagination_respects_filters_sorting_and_out_of_range_pages(): void
    {
        $this->freezeTime();
        $company = Company::factory()->create();
        $anna = Employee::factory()->for($company)->create(['first_name' => 'Anna', 'last_name' => 'Kowalska']);
        $zofia = Employee::factory()->for($company)->create(['first_name' => 'Zofia', 'last_name' => 'Zielińska']);
        $records = WorkflowRecord::factory()->for($company)->for($anna)->count(51)->create(['status' => 'signed', 'department' => 'p1']);
        $other = WorkflowRecord::factory()->for($company)->for($zofia)->create(['status' => 'signed', 'department' => 'p2']);
        $url = route('companies.board', [$company, 'contracts', 'q' => 'Kowalska', 'status' => 'signed', 'department' => 'p1', 'sort' => 'oldest', 'page_signed' => 999]);

        $this->actingAs($company->owner)->withHeader('X-Board-Group', 'signed')->get($url)->assertOk()
            ->assertSee(route('companies.records.show', [$company, $records->first()]), false)
            ->assertDontSee(route('companies.records.show', [$company, $other]), false)
            ->assertSee('51-51 z 51')->assertSee('data-current-page="2"', false)->assertSee('q=Kowalska');
    }

    public function test_monday_groups_keep_source_order_and_have_independent_ajax_pages(): void
    {
        $board = MondayBoard::factory()->create(['groups' => ['Podpisane / bieżące', 'Rozwiązane i archiwum']]);
        $current = MondayItem::factory()->for($board, 'board')->count(41)->sequence(fn (Sequence $sequence): array => ['position' => $sequence->index, 'group_name' => 'Podpisane / bieżące', 'name' => 'Bieżąca '.$sequence->index, 'fields' => ['A' => 'Bieżąca '.$sequence->index, 'B' => 'Podpisana', 'C' => '44051401458']])->create();
        MondayItem::factory()->for($board, 'board')->count(41)->sequence(fn (Sequence $sequence): array => ['position' => $sequence->index + 50, 'group_name' => 'Rozwiązane i archiwum', 'name' => 'Archiwalna '.$sequence->index, 'fields' => ['A' => 'Archiwalna '.$sequence->index]])->create();
        $url = route('monday.boards.show', $board);
        $response = $this->actingAs($board->company->owner)->get($url)->assertOk()->assertSee('Wpisy: 82')->assertSee('Elementy: 41')
            ->assertSeeInOrder(['Podpisane / bieżące', 'Bieżąca 0', 'Rozwiązane i archiwum', 'Archiwalna 0'])->assertDontSee('Bieżąca 40');
        preg_match('/data-paginated-group="([^"]+)"/', $response->getContent(), $match);
        $key = $match[1];

        $this->get($url.'?page_'.$key.'=2')->assertOk()->assertSee('Bieżąca 40')->assertSee('Archiwalna 0')->assertDontSee('Bieżąca 0');
        $this->withHeader('X-Board-Group', $key)->get($url.'?page_'.$key.'=2&columns_custom=1&columns[]=A&columns[]=C&q=44051401458&sort=source')->assertOk()
            ->assertSee('Bieżąca 40')->assertSee('44051401458')->assertSee('41-41 z 41')->assertDontSee('Archiwalna')->assertDontSee('<html', false)
            ->assertSee(route('monday.items.show', [$board, $current->last()]), false)->assertSee('columns_custom=1');
    }

    public function test_ungrouped_monday_items_do_not_mix_with_a_group_named_bez_grupy(): void
    {
        $board = MondayBoard::factory()->create(['groups' => ['Bez grupy']]);
        MondayItem::factory()->for($board, 'board')->create(['name' => 'Nazwana grupa', 'group_name' => 'Bez grupy', 'fields' => ['A' => 'Nazwana grupa']]);
        MondayItem::factory()->for($board, 'board')->create(['name' => 'Bez przypisania', 'group_name' => null, 'fields' => ['A' => 'Bez przypisania']]);

        $response = $this->actingAs($board->company->owner)->get(route('monday.boards.show', $board))->assertOk()
            ->assertSee('Nazwana grupa')->assertSee('Bez przypisania');
        preg_match_all('/data-paginated-group="([^"]+)"/', $response->getContent(), $matches);
        $this->assertCount(2, array_unique($matches[1]));
    }

    public function test_ajax_pagination_keeps_authentication_company_and_group_boundaries(): void
    {
        $board = MondayBoard::factory()->create();
        WorkflowRecord::factory()->for($board->company)->create(['status' => 'signed']);
        $nativeUrl = route('companies.board', [$board->company, 'contracts']);
        $sourceUrl = route('monday.boards.show', $board);

        $this->withHeader('X-Board-Group', 'signed')->get($nativeUrl)->assertRedirect(route('login'));
        $this->get($sourceUrl)->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get($nativeUrl)->assertNotFound();
        $this->get($sourceUrl)->assertNotFound();
        $this->actingAs($board->company->owner)->withHeader('X-Board-Group', 'unknown')->get($nativeUrl)->assertNotFound();
        $this->get($sourceUrl)->assertNotFound();
    }

    public function test_admin_user_list_is_paginated_with_an_ajax_fragment(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'A Administrator']);
        $users = User::factory()->count(50)->sequence(fn (Sequence $sequence): array => ['name' => sprintf('Użytkownik %03d', $sequence->index)])->create();
        $url = route('admin.users');

        $this->actingAs($admin)->withHeader('X-Board-Group', 'users')->get($url.'?page_users=2')->assertOk()
            ->assertSee($users->last()->name)->assertDontSee($users->first()->name)->assertDontSee('<html', false)->assertSee('51-51 z 51');
        $this->actingAs($users->first())->get($url.'?page_users=2')->assertForbidden();
    }
}
