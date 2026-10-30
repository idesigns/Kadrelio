# Wysyłka e-mail w Kadrelio

Stan: 28.10.2026. Dodano wysyłkę przez API Brevo oraz tryb testowy. Wysyłka lokalna pozostaje wyłączona.

Obie firmy (OTC 7 i Approval Center) mają obecnie adres HR `admin@kadrelio.test`. To adres testowy. Docelową skrzynkę HR trzeba wskazać osobno w ustawieniach każdej firmy przed uruchomieniem produkcji. Adresy pracowników pozostają zgodne z danymi profili. Przy wyłączonej poczcie wysyłka jest całkowicie zatrzymana; po włączeniu w trybie testowym odbiorca jest zmieniany dopiero przed przekazaniem wiadomości do transportu.

## Zabezpieczenie testów i migracji

- Domyślnie `HR_MAIL_ENABLED=false`. W takim trybie nowe wiadomości nie są wysyłane ani odkładane do późniejszej wysyłki.
- Wysyłka wymaga środowiska `APP_ENV=production` i jawnego `HR_MAIL_ENABLED=true`. Samo podanie danych API lub SMTP nie włącza wysyłki.
- Domyślny `HR_MAIL_MODE=test` kieruje całą pocztę na `HR_MAIL_TEST_ADDRESS` (domyślnie `testy@kadrelio.pl`), usuwa DW i UDW, ustawia odpowiedzi na skrzynkę testową i dodaje `[TEST]` do tematu. Dotyczy wszystkich transportów, nie tylko Brevo. Nie zmienia adresów w bazie.
- Normalni odbiorcy są dostępni dopiero po jawnym `HR_MAIL_MODE=live`. Nieznany tryb lub niepoprawny adres testowy blokuje pocztę zamiast przywracać oryginalnych odbiorców.
- Lokalne środowisko i staging nie mogą wysyłać prawdziwych maili. Testy automatyczne używają atrap poczty i transportu `array`; dodatkowa blokada zatrzymuje rzeczywisty transport podczas testów.
- Zadania i wiadomości zapamiętują zgodę oraz tryb wysyłki w chwili utworzenia. Stare zadania bez tych informacji, wiadomości utworzone przy wyłączonej poczcie i zadania z innego trybu są blokowane. Przełączenie z test na live nie wysyła zaległych testów do pracowników.
- Zadanie dokumentu zatrzymane przez blokadę otrzymuje status `suppressed`, bez oznaczenia jako wysłane. Ponowna wysyłka oświadczenia wymaga jawnej akcji HR po uruchomieniu poczty.
- Import Monday, naprawa mapowania profili i odtwarzanie historycznych statusów nie wysyłają zaproszeń, dokumentów ani oświadczeń. Nie uruchamiają ponownie starych podpisów.
- `monday:refresh-projection` domyślnie tylko analizuje dane; `--apply` zapisuje brakujące odpowiedzi i statusy. Polecenie na czas działania dodatkowo wyłącza pocztę we własnym procesie. Nie wybiera nierozstrzygniętych profili ani nie tworzy zadań wysyłki.
- W momencie wdrożenia blokady lokalna kolejka była pusta. Nie usunięto żadnych zadań ani danych z bazy.

## Kiedy aplikacja wysyła wiadomości

Poniższe reguły pokazują docelowych odbiorców w trybie live. W trybie test wszystkie poniższe wiadomości trafiają wyłącznie na skrzynkę testową.

| Sytuacja | Odbiorca | Zawartość | Co uruchamia wysyłkę |
| --- | --- | --- | --- |
| Zaproszenie użytkownika do firmy | Adres wpisany w zaproszeniu | Link do przyjęcia zaproszenia i dostępu do firmy | Manager/admin zapisuje zaproszenie w zakładce użytkowników |
| Zgłoszenie nowej umowy dla osoby bez uzupełnionego profilu | E-mail pracownika ze zgłoszenia | Indywidualny link do uzupełnienia danych, ważny 30 dni | HR zatwierdza zgłoszenie w panelu albo rekruter zatwierdza zewnętrzny formularz po wpisaniu hasła firmy |
| Przypomnienie o formularzu danych | E-mail pracownika | Link do uzupełnienia danych; wygasły link jest odnawiany | HR klika wysłanie przypomnienia; samo otwarcie profilu niczego nie wysyła |
| Wysłanie formularza danych osobowych | Adres HR ustawiony dla konkretnej firmy | Informacja o uzupełnieniu danych i link do profilu w panelu | Pracownik zatwierdza formularz ogólny lub indywidualny |
| Wysłanie umowy, aneksu lub rozwiązania do podpisu | E-mail pracownika | PDF dokumentu/pakietu i indywidualny link do oświadczenia/podpisu, ważny 30 dni | HR klika „Wyślij do podpisu” albo ponowną wysyłkę |
| Podpisanie umowy, aneksu lub rozwiązania | Adres HR ustawiony dla firmy dokumentu | PDF oświadczenia z danymi, datą i podpisem | Pracownik zatwierdza podpis; ponowne otwarcie zużytego linku niczego nie wysyła |
| Zgłoszenie zmiany danych lub Multisportu | E-mail zapisany w istniejącym profilu pracownika | Jednorazowy link potwierdzający zgłoszenie, ważny 30 minut | Pracownik zatwierdza formularz; e-mail i PESEL muszą pasować do profilu |
| Potwierdzenie zmiany danych lub Multisportu | Adres HR ustawiony dla firmy | PDF podpisanego oświadczenia | Pracownik otwiera link z e-maila i zatwierdza zgłoszenie |
| Ponowienie nieudanej lub zablokowanej wysyłki oświadczenia | Adres HR ustawiony dla firmy dokumentu | PDF już zapisanego oświadczenia | HR ręcznie wybiera ponowienie; nie jest to automatyczne nadrobienie migracji |

Automatyczne ponowienia błędu transportu dotyczą wyłącznie wcześniej zatwierdzonej wysyłki: maksymalnie 3 próby, z odstępami 10 i 60 sekund. Przy wyłączonej wysyłce nie dochodzi do próby transportu.

## Czynności bez wiadomości e-mail

Logowanie i rejestracja managera, przeglądanie tablic/profili, wyszukiwanie, kopiowanie linku, pobieranie plików, edycja danych, wybór wersji profilu, kontrola/powiązanie importowanych wpisów, generowanie i podgląd PDF, wgrywanie/aktywacja/wycofanie wzoru oraz sam import lub migracja nie wysyłają maili.

Dane istniejącego pracownika nie są ponownie rozsyłane tylko dlatego, że zostały odczytane lub odtworzone z Monday. Importowane dokumenty nie są ponownie wysyłane do podpisania bez przejęcia sprawy do nowego obiegu i jawnego kliknięcia wysyłki.

## Przed świadomym uruchomieniem produkcyjnej poczty

1. Potwierdzić adresy HR obu firm oraz produkcyjny adres `APP_URL` z HTTPS.
2. Sprawdzić nadawcę i konfigurację API/SMTP oraz dostępność kolejki i generatora PDF.
3. Sprawdzić zastaną kolejkę i konfigurację środowiska; import nie jest poleceniem wysłania zaległych wiadomości.
4. Ustawić `HR_MAIL_ENABLED=true` po zakończeniu migracji. Do testów pozostawić `HR_MAIL_MODE=test` i wskazać działający adres odbiorczy. Po zaakceptowaniu odbiorców/scenariuszy można przełączyć na `HR_MAIL_MODE=live`.
5. Odświeżyć konfigurację i zrestartować procesy kolejki, aby używały aktualnej blokady.

Przy późniejszej migracji na działającej produkcji najpierw ustawić `HR_MAIL_ENABLED=false`, odświeżyć konfigurację i zrestartować workery. Samo wyłączenie poczty w procesie importu nie zmienia konfiguracji innych, już uruchomionych procesów. Wysyłkę włączyć ponownie dopiero po sprawdzeniu migracji.

## Konfiguracja API Brevo w Forge

Po uwierzytelnieniu domeny i nadawcy w Brevo oraz uruchomieniu skrzynki odbiorczej ustaw w Environment:

```dotenv
MAIL_MAILER=brevo
BREVO_API_KEY=klucz_wprowadzony_w_Forge
MAIL_FROM_ADDRESS=powiadomienia@kadrelio.pl
MAIL_FROM_NAME=Kadrelio
HR_MAIL_ENABLED=true
HR_MAIL_MODE=test
HR_MAIL_TEST_ADDRESS=testy@kadrelio.pl
```

`HR_MAIL_TEST_ADDRESS` może wskazywać inną istniejącą skrzynkę używaną do testów. Sam adres w konfiguracji nie tworzy skrzynki pocztowej. Brevo służy tutaj do wysyłki po HTTPS; odbiór wymaga osobnej skrzynki lub przekierowania.

Po wdrożeniu kodu i zapisaniu konfiguracji uruchom `php artisan config:cache` i `php artisan queue:restart`. Worker kolejki musi działać. Transport przekazuje HTML, linki i załączniki PDF przez API; nie wymaga otwierania portów SMTP na DigitalOcean.

## Monitoring kolejki w Horizon

Panel `/horizon` i całe jego API wymagają zalogowanego administratora `admin@kadrelio.pl`. Ograniczenie działa również lokalnie. Horizon pokazuje zadania przetwarzane przez Redis po jego uruchomieniu; dotychczasowe wpisy `failed_jobs` pozostają w bazie.

Na serwerze Redis i rozszerzenie PHP zostały sprawdzone. Po wdrożeniu kodu ustaw w Forge Environment:

```dotenv
QUEUE_CONNECTION=redis
```

Zachowaj aktualne ustawienia `HR_MAIL_MODE=test` i `HR_MAIL_TEST_ADDRESS`. Następnie odśwież konfigurację i obsłuż oczekujące zadania starej kolejki:

```bash
cd /home/forge/kadrelio.pl/current
php artisan config:cache
php artisan queue:work database --queue=default --stop-when-empty --tries=3 --timeout=80
```

Drugie polecenie wykonuje wcześniej zlecone zadania z bazy, zgodnie z aktualnymi blokadami poczty. Nie ponawia zadań z `failed_jobs`. Nowe zadania trafiają już do Redis. Nie czyść ani nie usuwaj starej kolejki.

W Forge, w Overview witryny `kadrelio.pl`, włącz **Laravel Horizon** i **Laravel Scheduler**. Forge utworzy trwały proces `php artisan horizon` i scheduler. Nie dodawaj osobnego workera Redis. Przy kolejnych wdrożeniach proces Horizon musi otrzymać `php artisan horizon:terminate`; integracja Forge dodaje tę czynność do wdrożenia.

Horizon utrzymuje jeden worker, maksymalnie trzy próby i timeout 80 sekund. Timeout istniejącego zadania dokumentu wynosi 70 sekund, a `retry_after` Redis domyślnie 90 sekund. Metryki są zapisywane co pięć minut; widok zadań obejmuje ostatnią dobę, a błędów ostatni tydzień. Status procesu sprawdzisz przez `php artisan horizon:status`.

Nie włączono poczty ani nie wysłano wiadomości przez rzeczywisty transport w ramach tych prac.
