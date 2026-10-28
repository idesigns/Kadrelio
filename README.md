# Kadrelio

Panel kadrowy Laravel 13. Wymagania: PHP zgodny z composer.lock (co najmniej 8.3), rozszerzenia SQLite lub docelowej bazy, ZIP, SimpleXML, mbstring i OpenSSL, Node zgodny z package-lock.json oraz Chromium do generowania nowych PDF. Lokalna domena: http://kadrelio.test, obsługiwana przez Herd/Nginx.

## Migracja z Monday

Importer zachowuje wszystkie tablice, grupy, kolumny (także powtórzone nagłówki), źródłowe wartości komórek, identyfikatory, komentarze, zespół i odwołania do plików. Oryginalne XLSX trafiają do prywatnego storage. Wrażliwe wartości w bazie są szyfrowane przez APP_KEY. Powtórzone formularze pozostają oddzielnymi wersjami; niejednoznaczne relacje wymagają decyzji w panelu „Dane z Monday”.

Eksport z 21.10.2026: 37 tablic, 7 845 wpisów, 1 621 komentarzy, 8 kont w eksporcie zespołu, 13 717 identyfikatorów zasobów. Obie firmy mają oddzielne dane. 3 181 wpisów umów, aneksów, rozwiązań, zmian danych i zgód Multisport jest dodatkowo powiązanych z obiegiem Kadrelio. Pozostałe typy, w tym sprzęt i NDA, są zachowane w pełnych tablicach źródłowych.

### Analiza bez zapisu

Katalog eksportu ma zawierać foldery `boards`, `updates` i `team`. Assets są dołączane osobno. Polecenie bez `--commit` nie zapisuje danych ani kopii plików:

```bash
php artisan monday:import /srv/private/monday-export --json --no-interaction
```

### Zapis eksportu

Przed zapisem wykonaj spójną kopię docelowej bazy i prywatnych plików. Polecenie tworzy oddzielne firmy, zachowując dotychczasowe dane. W `--owner` podaj ID istniejącego administratora lub managera. Nie uruchamiaj seedera demo na produkcji.

```bash
php artisan migrate --force --no-interaction
php artisan monday:import /srv/private/monday-export --owner=123 --commit --force --json --no-interaction
php artisan monday:verify --files --no-interaction
```

Zastąp `123` rzeczywistym ID właściciela. Domyślny klucz eksportu to `monday-26363471`. Ponowienie z tym samym kluczem i identycznymi plikami nie dubluje wpisów ani nie nadpisuje zmian wykonanych w panelu. Inny zestaw plików pod tym samym kluczem jest odrzucany. Nowy eksport wymaga nowego klucza i osobnej migracji; nie jest automatyczną synchronizacją z Monday.

Opcjonalnie można wskazać istniejące puste firmy: `--company=otc:12 --company=approval:13`. Firma docelowa musi być dostępna dla właściciela, bez danych demonstracyjnych, pracowników, dokumentów i wcześniejszego importu.

Cały zapis bazy jest transakcją. Import nie wysyła wiadomości, nie aktywuje kont z Monday, nie nadaje im uprawnień i nie tworzy nowych podpisów. Status „Podpisana” z Monday jest stanem historycznym; nie powstaje fikcyjna data podpisu ani nowe poświadczenie. Historyczne dokumenty nie mogą być ponownie generowane lub wysyłane jako nowe.

### Dołączenie assets

Pliki pozostają poza publicznym katalogiem aplikacji. Dopasowanie odbywa się po resource ID z nazw lub katalogów eksportu, nigdy po samym nazwisku czy nazwie PDF. Najpierw sprawdź wynik bez zapisu:

```bash
php artisan monday:assets /srv/private/monday-export/assets --no-interaction
php artisan monday:assets /srv/private/monday-export/assets --commit --force --no-interaction
php artisan monday:verify --files --no-interaction
```

Gdy ścieżki nie zawierają resource ID, można przekazać `--map=/srv/private/assets-map.json`. Format mapy to obiekt JSON, np. `{"987654321":"podfolder/umowa.pdf"}`. Ścieżki są względne wobec katalogu assets. Pliki poza tym katalogiem i dowiązania są odrzucane. Różne pliki dla jednego ID tworzą konflikt; istniejący oryginał nie jest nadpisywany. Ponowienie po przerwaniu pomija zgodne pliki. Importer nie pobiera niczego z serwerów Monday.

### Praca z wyjątkami

W panelu „Dane z Monday” dostępne są wyszukiwanie po wszystkich kolumnach, wybór kolumn, grupy, statusy, komentarze i pełny XLSX. Zakładka „Do sprawdzenia” zbiera niejednoznaczne relacje oraz dane wymagające weryfikacji. Manager może wskazać właściwy dokument, wybrać aktualną wersję profilu i potwierdzić sprawdzenie danych z uzasadnieniem. Zmiany są audytowane, z ochroną przed nadpisaniem równoczesnej edycji. Oryginalne daty, identyfikatory i pliki historyczne pozostają zachowane.

Arkusze nie zawierają pełnej definicji automatyzacji Monday ani ID wszystkich połączeń między tablicami. Niepewne połączenia pozostają jawnie oznaczone. Import XLSX nie odtwarza brakujących treści szablonów umów ani nie zastępuje oryginalnych PDF z assets.

## Przeniesienie na produkcję

Produkcja kadrelio.pl korzysta z MySQL. Lokalna paczka zawiera kopię SQLite wyłącznie jako źródło danych. Komenda `kadrelio:import-snapshot` przepisuje jej rekordy do pustej bazy MySQL, zachowując identyfikatory, powiązania, poprawki, hasła, tokeny i zaszyfrowane wartości. SQLite nie staje się produkcyjnym silnikiem bazy. Do odczytu paczki potrzebne jest rozszerzenie PHP `pdo_sqlite`, a do zapisu `pdo_mysql`.

W Forge ustaw `DB_CONNECTION=mysql` oraz rzeczywiste `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` i `DB_PASSWORD`. Zachowaj dotychczasowy `APP_KEY`, przekazany poufnie przez Environment. Ustaw `APP_URL=https://kadrelio.pl` i `HR_MAIL_ENABLED=false`; odśwież konfigurację. Prywatne pliki należą do trwałego shared storage, a nie katalogu konkretnego wydania kodu.

Paczkę `kadrelio-state.tar.gz` i jej plik SHA-256 prześlij przez SSH/SFTP poza katalog `public`. Po sprawdzeniu sumy rozpakuj ją do osobnego katalogu, np. `/home/forge/kadrelio-transfer`. Katalog ma zawierać `database.sqlite`, `manifest.json` oraz `private`. Pliku SQLite nie kopiuj do aktywnej bazy aplikacji.

Z katalogu aplikacji, po wdrożeniu aktualnego kodu i konfiguracji MySQL:

```bash
php artisan config:clear
php artisan migrate --force --no-interaction
php artisan kadrelio:import-snapshot /home/forge/kadrelio-transfer --no-interaction
```

Ostatnie polecenie tylko analizuje: sprawdza schemat, pustą bazę docelową, zgodność APP_KEY, integralność kopii i wszystkich plików oraz komplet dokumentów wskazanych w bazie. Zapis uruchamiaj po poprawnym wyniku analizy, w trybie konserwacji:

```bash
php artisan down
php artisan kadrelio:import-snapshot /home/forge/kadrelio-transfer --apply --no-interaction
php artisan monday:verify --files --no-interaction
php artisan config:cache
php artisan up
```

Jeśli zapis lub kontrola zgłoszą błąd, pozostaw aplikację w trybie konserwacji do wyjaśnienia. Import działa w transakcji i sprawdza wartości wszystkich rekordów przed zatwierdzeniem. Klucze obce pozostają włączone; samopowiązania są uzupełniane po wstawieniu rekordów. Różnice reprezentacji pól DATE i JSON między silnikami są porównywane według ich wartości. Istniejące dane blokują import, a istniejące pliki mogą być użyte tylko przy identycznej sumie kontrolnej. Nie ma trybu czyszczenia ani nadpisania bazy.

Import pomija sesje, cache, resetowanie haseł i całą kolejkę zadań, w tym błędne zadania. Nie uruchamia zdarzeń modeli ani wysyłki wiadomości. W przypadku błędu zapisu baza zostaje wycofana, natomiast poprawnie skopiowane prywatne pliki pozostają i mogą zostać ponownie użyte. Kopia samego kodu lub samych tabel Monday nie obejmuje kartotek, ustawień, dostępów i historii decyzji. Nie uruchamiaj `key:generate` po przeniesieniu zaszyfrowanej bazy.

Alternatywą jest świeża baza produkcyjna i ponowny import oryginalnego eksportu powyższymi poleceniami. Taki import odtwarza stan źródłowy, bez lokalnych późniejszych decyzji i edycji.

Na docelowym serwerze:

1. Skieruj domenę HTTPS na katalog `public`. Ustaw `APP_ENV=production`, `APP_DEBUG=false`, docelowe `APP_URL`, `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true`, `HR_DEMO_ENABLED=false` i prawidłową strefę `Europe/Warsaw`.
2. Zainstaluj zależności z lockfile i zbuduj zasoby (`composer install --no-dev --optimize-autoloader`, `npm ci`, `npm run build`). Zachowaj prywatny storage poza katalogiem publicznym. Ustaw poprawną ścieżkę `HR_PDF_BINARY` do Chromium na serwerze.
3. Uruchom wyłącznie addytywne migracje (`php artisan migrate --force --no-interaction`). Następnie `php artisan optimize`. Nie używaj poleceń odtwarzających lub czyszczących bazę.
4. Uzupełnij rzeczywiste dane obu firm, NIP, adres, e-mail HR, link RODO, szablony dokumentów i dostęp zespołu. Skonfiguruj produkcyjne SMTP oraz worker `php artisan queue:work`. Nowe formularze firmy pozostają nieaktywne do uzupełnienia HR i RODO.
5. Uruchom `php artisan monday:verify --files --production --no-interaction`. Polecenie zgłosi brakujące pliki, nierozstrzygnięte relacje, niezweryfikowane dane i braki konfiguracji. Zwykły raport zgodności liczebności nie oznacza pełnej gotowości całego obiegu do produkcji.

## Testy

Podstawowe testy korzystają z odizolowanej bazy SQLite w pamięci. Testy importu obejmują pełny odczyt XLSX, wersje profili, izolację firm, brak wysyłki, idempotencję, konflikty assets, audyt, równoczesne edycje i uprawnienia do plików. Test paczki tworzy osobną kopię SQLite jako plik wejściowy; test rzeczywistego MySQL wymaga jawnego `KADRELIO_MYSQL_TEST_SOCKET` wskazującego odizolowaną instancję w katalogu `/tmp/kadrelio-mysql-check-*`. Tworzy nową bazę o losowej nazwie, nie czyści istniejących baz.

```bash
php artisan test --compact tests/Feature/MondayImportTest.php tests/Feature/MondayAccessTest.php
MONDAY_EXPORT_PATH=/srv/private/monday-export php artisan test --compact tests/Feature/MondayImportTest.php
```

Druga komenda sprawdza rzeczywisty eksport, wszystkie tablice i przykładowe szczegóły każdego typu. Nie modyfikuje źródłowych arkuszy ani bazy aplikacji. Testy PDF wymagają możliwości uruchomienia Chromium z aktywnym sandboxem systemowym.
