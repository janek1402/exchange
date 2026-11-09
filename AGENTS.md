# Instrukcje dla agenta AI

Aplikacja kantoru (PHP 8.5, Symfony 8, PHPUnit 13, Doctrine DBAL, MariaDB 11.4). Obsługuje prawdziwe pieniądze klientów.
Właściciel nie jest programistą: tłumacz zmiany prostym językiem i zawsze ostrzegaj, gdy zmiana dotyka pieniędzy.

## Środowisko
- Wszystko działa w Dockerze. Nie uruchamiaj PHP ani composera na hoście.
- Testy: `docker exec php-fpm composer tests`
- Konsola Symfony: `docker exec php-fpm php bin/console <komenda>`

## Zasady pracy
- Jedna zmiana naraz. Po każdej zmianie uruchom testy i pokaż wynik.
- Zmianę w logice pieniędzy (przelewy, spread, antyfraud, saldo) zaczynaj od testu, który pokazuje problem.
  Po takiej zmianie wykonaj przegląd według `docs/przeglad-zmian-w-pieniadzach.md`
  (w Claude Code: subagent `money-reviewer`) i pokaż werdykt.
- Nigdy nie zmieniaj oczekiwań w istniejących testach tylko po to, żeby przeszły.
  Jeśli uważasz, że test jest błędny, zatrzymaj się i wyjaśnij dlaczego.
- Nie usuwaj i nie pomijaj testów.
- Nie edytuj plików `.env*` ani istniejących migracji.
- Nie zmieniaj `.github/`, `.claude/` ani `AGENTS.md` bez wyraźnej prośby.
- Nie ruszaj niezacommitowanych zmian, których nie zrobiłeś. Zapytaj o nie.
- Nie rób commitów ani `git push` bez wyraźnej prośby.

## Baza danych
- Zmiany schematu tylko przez nową migrację w `migrations/`. Nigdy ręczny SQL zmieniający bazę
  (`UPDATE`, `INSERT`, `ALTER`, `DROP`...). Zapytania `SELECT` do diagnozy są dozwolone.
- Migracje uruchamia człowiek: `docker exec -it php-fpm php bin/console doctrine:migrations:migrate`.
- Testy jednostkowe nie dotykają bazy. Zielone testy nie gwarantują, że migracje i zapis do bazy działają.

## Reguły biznesowe

### Przelewy (model rezerwacji)
- Utworzenie przelewu NIE przesuwa pieniędzy. Blokuje kwotę na portfelu nadawcy (`Wallet::reserve()`),
  saldo (`balance`) się nie zmienia.
- Dostępne środki = `balance - reservedBalance`. Każda kontrola "czy klienta stać" używa dostępnych środków.
- `complete()`: zmniejsza saldo i rezerwację nadawcy, dopisuje kwotę odbiorcy.
- `reject()`: tylko zwalnia rezerwację (`release()`). Saldo bez zmian.
- Brak portfela docelowego przy przetwarzaniu = odrzucenie ze zwolnieniem rezerwacji nadawcy.
  Środki klienta nigdy nie mogą zostać zablokowane na stałe.
- Transakcję przetwarza się tylko ze statusu `PENDING` lub `FRAUD_REVIEW`. Ponowne przetworzenie to błąd.
- `release()` większy niż rezerwacja to błąd księgowy: wyjątek, nigdy ciche obcięcie.
- Zakazane: przelew na ten sam portfel, kwoty zerowe i ujemne.
- Zablokowany portfel: nowy przelew z niego lub na niego = 422; przy przetwarzaniu przelew
  jest odrzucany ze zwolnieniem rezerwacji.

### Antyfraud
- Próg: przelew o wartości POWYŻEJ 15 000 EUR (ściśle `>`).
- Wartość liczona w EUR z kwoty wysyłanej przez nadawcę, PRZED odjęciem spreadu, niezależnie od walut przelewu.
- Takie przelewy mają status `FRAUD_REVIEW` i wymagają ręcznej akceptacji właściciela.
  Domyślna odpowiedź w potwierdzeniu to "nie". Tryb nieinteraktywny (`-n`) pomija kolejkę antyfraudu,
  nigdy jej nie zatwierdza ani nie odrzuca.
- Nie zmieniaj progu, waluty progu ani sposobu przeliczania bez wyraźnej zgody właściciela.

### Spread i zarobek kantoru
- Kantor zarabia na spreadzie przy każdym przewalutowaniu. Wzór jest w README.md; nie zmieniaj go bez zgody właściciela.
- Kwoty klienta (przelew, wpłata, kwota otrzymana) tylko w pełnych jednostkach waluty:
  2 miejsca po przecinku, JPY 0 (`Currency::decimals()`). Część grosza = 400.
- Klient dostaje kwotę po przeliczeniu i odjęciu spreadu ze wzoru, zaokrągloną W DÓŁ do pełnych groszy.
  Cała reszta to spread kantoru, zaokrąglony W GÓRĘ do 4 miejsc. Kantor nigdy nie dostaje mniej niż wzór.
- Przelew, w którym klient po przeliczeniu i odjęciu spreadu dostałby mniej niż 1 grosz (jen)
  waluty docelowej, jest odrzucany (400). Pieniądze zostają na portfelu nadawcy.
- Liczba miejsc po przecinku i kierunek zaokrągleń decydują, kto dostaje ułamek grosza: klient
  czy kantor. Nie zmieniaj ich bez zgody właściciela, także wtedy, gdy wymaga tego test.
- Spread z każdego rozliczonego przelewu trafia do portfela firmy (`company_wallets`).

### Portfele i dostęp
- Użytkownik ma dostęp wyłącznie do swoich portfeli. Cudzy portfel = odpowiedź 404 (nie 403),
  żeby nie zdradzać, że istnieje.
- Saldo portfela nie może być ujemne.

### Usuwanie portfela (`DELETE /api/wallets/{id}`)
- Tylko soft delete (`deleted_at`). Wiersza nigdy nie usuwaj: wskazuje na niego historia transakcji.
- Usunięty portfel nie istnieje dla aplikacji: każde zapytanie o portfele musi pomijać
  `deleted_at IS NOT NULL` (przelew, wpłata, lista, ponowne usunięcie = 404).
- Odmowa 409, gdy: rezerwacja > 0, istnieje przelew PENDING/FRAUD_REVIEW z portfela LUB na portfel
  (przychodzący nie zostawia rezerwacji), saldo warte 0,01 EUR lub więcej. Zablokowany portfel = 422.
- Saldo warte mniej niż 0,01 EUR (resztka, której nie da się wydać) trafia do portfela firmy w tej
  samej transakcji DB. Progu nie zmieniaj bez zgody właściciela.
- Jeden AKTYWNY portfel na użytkownika i walutę (indeks z kolumną `active_flag`); usuniętych może być wiele.
