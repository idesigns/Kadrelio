# Wysyłka e-mail w Kadrelio

Stan: 24.10.2026. Wysyłka w bieżącym środowisku jest wyłączona.

Obie firmy (OTC 7 i Approval Center) mają obecnie adres HR `admin@kadrelio.test`. To adres testowy. Docelową skrzynkę HR trzeba wskazać osobno w ustawieniach każdej firmy przed uruchomieniem produkcji. Adresy pracowników pozostają zgodne z danymi profili; blokada nie zastępuje ich adresem testowym, tylko całkowicie zatrzymuje wysyłkę.

## Zabezpieczenie testów i migracji

- Domyślnie `HR_MAIL_ENABLED=false`. W takim trybie nowe wiadomości nie są wysyłane ani odkładane do późniejszej wysyłki.
- Wysyłka rzeczywista wymaga środowiska `APP_ENV=production` i jawnego `HR_MAIL_ENABLED=true`. Samo podanie danych SMTP nie włącza wysyłki.
- Lokalne środowisko i staging nie mogą wysyłać prawdziwych maili. Testy automatyczne używają atrap poczty i transportu `array`; dodatkowa blokada zatrzymuje rzeczywisty transport podczas testów.
- Zadania i wiadomości zapamiętują zgodę na wysyłkę w chwili utworzenia. Stare zadania sprzed tego zabezpieczenia oraz wiadomości utworzone przy wyłączonej wysyłce nie dostają zgody po późniejszym włączeniu poczty.
- Zadanie dokumentu zatrzymane przez blokadę otrzymuje status `suppressed`, bez oznaczenia jako wysłane. Ponowna wysyłka oświadczenia wymaga jawnej akcji HR po uruchomieniu poczty.
- Import Monday, naprawa mapowania profili i odtwarzanie historycznych statusów nie wysyłają zaproszeń, dokumentów ani oświadczeń. Nie uruchamiają ponownie starych podpisów.
- `monday:refresh-projection` domyślnie tylko analizuje dane; `--apply` zapisuje brakujące odpowiedzi i statusy. Polecenie na czas działania dodatkowo wyłącza pocztę we własnym procesie. Nie wybiera nierozstrzygniętych profili ani nie tworzy zadań wysyłki.
- W momencie wdrożenia blokady lokalna kolejka była pusta. Nie usunięto żadnych zadań ani danych z bazy.

## Kiedy aplikacja wysyła wiadomości

Poniższe reguły obowiązują po świadomym uruchomieniu wysyłki w produkcji.

| Sytuacja | Odbiorca | Zawartość | Co uruchamia wysyłkę |
| --- | --- | --- | --- |
| Zaproszenie użytkownika do firmy | Adres wpisany w zaproszeniu | Link do przyjęcia zaproszenia i dostępu do firmy | Manager/admin zapisuje zaproszenie w zakładce użytkowników |
| Zgłoszenie nowej umowy dla osoby bez uzupełnionego profilu | E-mail pracownika ze zgłoszenia | Indywidualny link do uzupełnienia danych, ważny 30 dni | HR zatwierdza zgłoszenie nowej umowy |
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
2. Sprawdzić nadawcę i konfigurację SMTP oraz dostępność kolejki i generatora PDF.
3. Sprawdzić zastaną kolejkę i konfigurację środowiska; import nie jest poleceniem wysłania zaległych wiadomości.
4. Ustawić `HR_MAIL_ENABLED=true` wyłącznie po zakończeniu migracji i zaakceptowaniu odbiorców/scenariuszy.
5. Odświeżyć konfigurację i zrestartować procesy kolejki, aby używały aktualnej blokady.

Przy późniejszej migracji na działającej produkcji najpierw ustawić `HR_MAIL_ENABLED=false`, odświeżyć konfigurację i zrestartować workery. Samo wyłączenie poczty w procesie importu nie zmienia konfiguracji innych, już uruchomionych procesów. Wysyłkę włączyć ponownie dopiero po sprawdzeniu migracji.

Nie włączono produkcyjnej poczty w ramach tych prac.
