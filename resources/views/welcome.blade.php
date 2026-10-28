@extends('layouts.base')
@section('title', 'Kadry, które po prostu działają')
@section('description', 'Kadrelio to panel kadrowy: dane pracowników, umowy, aneksy i podpisy online w jednym miejscu.')
@section('body-class', 'landing-body')
@section('body')
<header class="lp-nav">
<a href="{{ route('home') }}" class="brand">
<img class="brand-mark" src="{{ asset('kadrelio-icon.png') }}" width="32" height="32" alt="" aria-hidden="true">
<strong>Kadrelio</strong>
</a>
<nav class="lp-nav-actions" aria-label="Konto">
<a href="{{ route('login') }}" class="lp-nav-link">Zaloguj się</a>
<a href="{{ route('register') }}" class="lp-button lp-button-small">Załóż konto</a>
</nav>
</header>
<main>
<section class="lp-hero">
<p class="lp-eyebrow"><span class="lp-dots" aria-hidden="true"><i></i><i></i><i></i></span>Panel kadrowy dla działów HR</p>
<h1>Kadry, które po prostu <span class="lp-highlight">działają</span></h1>
<p class="lp-lead">Dane pracowników, umowy, aneksy i podpisy w jednym miejscu. Bez arkuszy i gonienia maili.</p>
<div class="lp-actions">
<a href="{{ route('register') }}" class="lp-button">Załóż konto <x-icon name="arrow" :size="18" /></a>
<a href="{{ route('login') }}" class="lp-button lp-button-ghost">Mam już konto</a>
</div>
<ul class="lp-boards" aria-label="Co prowadzisz w Kadrelio">
<li class="lp-board-green"><x-icon name="check" :size="12" />Umowy</li>
<li class="lp-board-blue"><x-icon name="check" :size="12" />Aneksy</li>
<li class="lp-board-red"><x-icon name="check" :size="12" />Rozwiązania</li>
<li class="lp-board-purple"><x-icon name="check" :size="12" />Oświadczenia</li>
<li class="lp-board-orange"><x-icon name="check" :size="12" />Multisport</li>
</ul>
</section>

<figure class="lp-product" aria-label="Podgląd tablicy umów w Kadrelio">
<span class="lp-pill lp-pill-red" aria-hidden="true"></span>
<span class="lp-pill lp-pill-yellow" aria-hidden="true"></span>
<span class="lp-pill lp-pill-green" aria-hidden="true"></span>
<div class="lp-window" aria-hidden="true">
<div class="lp-window-bar"><i></i><i></i><i></i></div>
<div class="lp-board">
<div class="lp-board-head">
<strong>Umowy</strong>
<span class="lp-board-new"><x-icon name="plus" :size="14" />Nowa umowa</span>
</div>
<p class="lp-group-title"><x-icon name="chevron" :size="16" />Październik <span>4 dokumenty</span></p>
<div class="lp-table">
<div class="lp-row lp-row-head"><span>Pracownik</span><span>Dokument</span><span>Status</span><span>Data</span></div>
<div class="lp-row"><span class="lp-person"><span class="avatar avatar-pink">AN</span>Anna Nowak</span><span>Umowa o pracę</span><span class="lp-status lp-status-signed">Podpisana</span><span>12.10</span></div>
<div class="lp-row"><span class="lp-person"><span class="avatar avatar-blue">PZ</span>Piotr Zieliński</span><span>Aneks: wynagrodzenie</span><span class="lp-status lp-status-sent">Wysłana do podpisu</span><span>14.10</span></div>
<div class="lp-row"><span class="lp-person"><span class="avatar avatar-orange">KW</span>Kasia Wójcik</span><span>Umowa zlecenie</span><span class="lp-status lp-status-cycle"><span class="lp-status-awaiting">Oczekuje na dane</span><span class="lp-status-ready">Gotowa do wysłania</span><span class="lp-status-sent">Wysłana do podpisu</span><span class="lp-status-signed">Podpisana</span></span><span>18.10</span></div>
<div class="lp-row"><span class="lp-person"><span class="avatar avatar-green">ML</span>Marek Lis</span><span>Rozwiązanie umowy</span><span class="lp-status lp-status-draft">W przygotowaniu</span><span>21.10</span></div>
</div>
</div>
</div>
<div class="lp-float lp-float-toast" aria-hidden="true"><span class="lp-float-icon"><x-icon name="check" :size="16" /></span><span><strong>Kasia Wójcik</strong> podpisała umowę</span></div>
</figure>

<section class="lp-section" aria-labelledby="lp-steps-title">
<h2 id="lp-steps-title">Od formularza do podpisu.<br>W trzech krokach.</h2>
<ol class="lp-steps">
<li class="lp-step lp-step-red lp-reveal">
<span class="lp-step-icon"><x-icon name="link" :size="22" /></span>
<h3>Wyślij link</h3>
<p>Pracownik sam uzupełnia dane w bezpiecznym formularzu. Trafiają prosto do kartoteki.</p>
</li>
<li class="lp-step lp-step-yellow lp-reveal">
<span class="lp-step-icon"><x-icon name="file-pen" :size="22" /></span>
<h3>Wygeneruj dokument</h3>
<p>Umowa, aneks lub rozwiązanie powstaje z Twojego szablonu. Bez przepisywania danych.</p>
</li>
<li class="lp-step lp-step-green lp-reveal">
<span class="lp-step-icon"><x-icon name="signature" :size="22" /></span>
<h3>Zbierz podpis</h3>
<p>Pracownik podpisuje online, a status na tablicy zmienia się sam.</p>
</li>
</ol>
</section>

<section class="lp-section lp-section-tight" aria-label="Możliwości">
<ul class="lp-features">
<li class="lp-reveal"><x-icon name="users" :size="22" /><strong>Kartoteka pracowników</strong><p>Dane, pliki i dokumenty każdej osoby w jednym widoku.</p></li>
<li class="lp-reveal"><x-icon name="folder" :size="22" /><strong>Wiele firm, jeden panel</strong><p>Oddzielne dane dla każdej firmy, zaproszenia i role dla zespołu.</p></li>
<li class="lp-reveal"><x-icon name="lock" :size="22" /><strong>Dane pod ochroną</strong><p>PESEL i dane osobowe są szyfrowane w bazie.</p></li>
<li class="lp-reveal"><x-icon name="board" :size="22" /><strong>Migracja z Monday</strong><p>Tablice, komentarze i pliki przenosisz razem z historią.</p></li>
</ul>
</section>

<section class="lp-cta lp-reveal" aria-labelledby="lp-cta-title">
<span class="lp-pill lp-pill-red" aria-hidden="true"></span>
<span class="lp-pill lp-pill-yellow" aria-hidden="true"></span>
<span class="lp-pill lp-pill-green" aria-hidden="true"></span>
<h2 id="lp-cta-title">Uporządkuj kadry jeszcze dziś</h2>
<p>Załóż konto managera i dodaj pierwszą firmę.</p>
<a href="{{ route('register') }}" class="lp-button">Załóż konto <x-icon name="arrow" :size="18" /></a>
</section>
</main>
<footer class="lp-footer">
<span><strong>Kadrelio</strong> · Kadry, które po prostu działają.</span>
<a href="{{ route('login') }}">Zaloguj się</a>
</footer>
@endsection
