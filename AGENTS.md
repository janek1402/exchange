# Instrukcje dla agenta AI

Aplikacja kantoru (PHP 8.5, Symfony 8, PHPUnit 13, Doctrine DBAL, MariaDB 11.4). Obsługuje prawdziwe pieniądze klientów.

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
- Nie edytuj plików `.env*` ani istniejących migracji. Zmiany schematu bazy rób nową migracją.
- Nie rób commitów ani `git push` bez wyraźnej prośby.

## Reguły biznesowe (wersja robocza, do uzupełnienia)
- Kantor zarabia na spreadzie przy każdym przewalutowaniu. Spread nigdy nie może działać na niekorzyść kantoru.
- Przelew o wartości powyżej 15 000 EUR wymaga ręcznej akceptacji właściciela, zanim pieniądze trafią do odbiorcy.
- Saldo portfela nie może być ujemne.
- Użytkownik ma dostęp wyłącznie do swoich portfeli.
