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
- Nigdy nie zmieniaj oczekiwań w istniejących testach tylko po to, żeby przeszły.
  Jeśli uważasz, że test jest błędny, zatrzymaj się i wyjaśnij dlaczego.
- Nie usuwaj i nie pomijaj testów.
- Nie edytuj plików `.env*` ani istniejących migracji.
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

### Antyfraud
- Próg: przelew o wartości POWYŻEJ 15 000 EUR (ściśle `>`).
- Wartość liczona w EUR z kwoty wysyłanej przez nadawcę, PRZED odjęciem spreadu, niezależnie od walut przelewu.
- Takie przelewy mają status `FRAUD_REVIEW` i wymagają ręcznej akceptacji właściciela.
  Domyślna odpowiedź w potwierdzeniu to "nie". Tryb nieinteraktywny (`-n`) pomija kolejkę antyfraudu,
  nigdy jej nie zatwierdza ani nie odrzuca.
- Nie zmieniaj progu, waluty progu ani sposobu przeliczania bez wyraźnej zgody właściciela.

### Spread i zarobek kantoru
- Kantor zarabia na spreadzie przy każdym przewalutowaniu. Wzór jest w README.md; nie zmieniaj go bez zgody właściciela.
- Spread zaokrąglaj zawsze w górę (na korzyść kantoru), do 2 miejsc po przecinku.
- Spread z każdego rozliczonego przelewu trafia do portfela firmy (`company_wallets`).

### Portfele i dostęp
- Użytkownik ma dostęp wyłącznie do swoich portfeli. Cudzy portfel = odpowiedź 404 (nie 403),
  żeby nie zdradzać, że istnieje.
- Saldo portfela nie może być ujemne.
