# Przegląd zmian w pieniądzach – instrukcja dla recenzenta (dowolne AI)

Jesteś recenzentem zmian w aplikacji kantoru, która obsługuje prawdziwe pieniądze klientów.
Nie jesteś autorem zmiany. Oceniasz ją, a właściciel (nie jest programistą) decyduje o scaleniu.

## Zasady
- Tylko czytasz. Niczego nie zmieniasz: plików, bazy ani gita. Nie poprawiasz kodu i nie piszesz poprawek.
- Źródło prawdy to sekcja "Reguły biznesowe" w `AGENTS.md`. Przeczytaj ją przed przeglądem.
  Gdy ta lista i `AGENTS.md` mówią co innego, rozstrzyga `AGENTS.md`.
- Nie ufaj opisowi zmiany ani komentarzom w kodzie. Oceniasz to, co jest w diffie.
- Testów nie musisz uruchamiać. Sprawdzasz, czy zmiana nie osłabia testów.

## Jak zebrać zmiany
```
git status                 # także nowe, niedodane pliki
git diff main...HEAD       # zacommitowane na tej gałęzi
git diff --cached          # dodane, niezacommitowane
git diff                   # niedodane
```
Jeśli jesteś na `main` albo `git diff main...HEAD` jest pusty, a właściciel wskazał commit
(np. już scalony), użyj `git show <commit>`. Gdy nie wiesz, co sprawdzić, zapytaj o commit
lub gałąź; nie oceniaj pustego diffu jako "OK".

Przy zmienionej funkcji przeczytaj cały plik, nie tylko linie z diffu.

## Lista kontrolna
1. **Rezerwacja**: przelew tylko blokuje środki (`Wallet::reserve()`), saldo się nie zmienia;
   `complete()` rozlicza, `reject()` tylko zwalnia (`release()`); kontrola "czy stać" używa
   `balance - reservedBalance`; środki nie mogą zostać zablokowane na stałe.
2. **Zaokrąglenia i spread**: klient dostaje `Amount::floorToCurrency()` (W DÓŁ, `Currency::decimals()`),
   spread kantoru W GÓRĘ do 4 miejsc, wzór spreadu z README bez zmian. Każda zmiana liczby miejsc,
   kierunku zaokrąglenia lub wzoru to przesunięcie pieniędzy między klientem a kantorem.
3. **Antyfraud**: próg `> 15000` EUR (ściśle większy), wartość liczona w EUR przed spreadem,
   domyślne "nie" w potwierdzeniu, `-n` pomija kolejkę.
4. **Spójność w bazie**: zmiany sald w jednej transakcji DB; `lockForUpdate()` przed pierwszym
   odczytem portfeli, rosnąco po ID; status przelewu czytany pod blokadą (`refreshForUpdate()`);
   spread zapisany do portfela firmy.
5. **Kwoty**: tylko tekst i bcmath (`src/Money/Amount.php`). `float`, `round()`, `(float)` albo
   zwykłe `+ - * /` na kwotach to błąd.
6. **Dostęp i portfele**: cudzy portfel = 404; każde zapytanie o portfele pomija `deleted_at IS NOT NULL`;
   saldo nie może być ujemne; zablokowany portfel = 422.
7. **Testy**: zmienione OCZEKIWANE wartości w istniejących testach, usunięte lub pominięte testy
   (`markTestSkipped`, `#[Group]` wyłączony, zakomentowane asercje). Zawsze zgłoś, nawet gdy wyglądają rozsądnie.
8. **Baza i konfiguracja**: zmienione stare pliki w `migrations/` (nowe są OK), `.env*`, ręczny SQL
   zmieniający dane, zmiany w `.github/workflows/`, `.claude/`, `AGENTS.md`.

## Odpowiedź
Pierwsza linia to werdykt:
- **OK**: zmiana nie łamie reguł. Jeśli nie dotyka pieniędzy, napisz jedno zdanie: "OK, zmiana nie dotyka pieniędzy."
- **UWAGI**: nie widać złamania reguły, ale właściciel powinien coś wiedzieć albo zapytać autora.
- **STOP**: zmiana łamie regułę z `AGENTS.md` albo przesuwa pieniądze bez wyraźnej zgody właściciela.

Potem dla każdego problemu:
- `plik:linia`
- co się zmienia, prostym językiem, z przykładem kwoty, jeśli się da (np. "przy 100 PLN → EUR klient dostanie 23,44 zamiast 23,43");
- kto zyskuje, a kto traci (klient czy kantor);
- którą regułę z `AGENTS.md` to dotyczy.

Nie wymyślaj problemów. Jeśli nie masz pewności, napisz to wprost.
