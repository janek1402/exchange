# Praca z agentem AI – instrukcja dla właściciela

Ta aplikacja obsługuje prawdziwe pieniądze klientów. Agent AI (Claude Code, Cursor, Copilot) może ją
rozwijać, ale ostatnie słowo masz zawsze Ty. Ta strona mówi, jak zlecać zmiany i kiedy powiedzieć "nie".

## Co Cię chroni

| Zabezpieczenie | Co robi | Działa w |
|---|---|---|
| `AGENTS.md` | Reguły dla agenta: pieniądze, spread, antyfraud, baza, testy. To wskazówki – agent może ich nie posłuchać. | większości narzędzi AI |
| `.claude/settings.json` | Utrudnia (nie blokuje całkowicie) groźne polecenia: edycję `.env` i starych migracji, `rm -rf`, `git push --force`. | tylko Claude Code |
| Hook testów | Zatrzymuje agenta, gdy testy są czerwone; jeśli nie naprawi ich po wezwaniu, ostrzega Cię "Nie akceptuj tej zmiany". | tylko Claude Code |
| Recenzent pieniędzy | Drugi agent sprawdza zmianę według [listy](przeglad-zmian-w-pieniadzach.md) i daje werdykt OK / UWAGI / STOP. To też tylko wskazówka – może się pomylić. | Claude Code: sam; inne AI: na Twoją prośbę (niżej) |
| CI na GitHubie | Przy każdym pull requeście: testy, migracje na czystej bazie, zakaz zmiany starych migracji. | zawsze |
| Ochrona gałęzi `main` | Zmiana wchodzi tylko przez pull request z zielonym CI. | zawsze |
| **Ty** | Czytasz podsumowanie agenta i decydujesz o scaleniu. | zawsze |

Żadne zabezpieczenie nie jest pełne. Najważniejsze są dwa ostatnie: zielone CI i Twoja decyzja.

## Jak zlecać zmiany

1. **Jedna zmiana na raz.** "Dodaj pole telefon do użytkownika", a nie "popraw wszystko".
2. **Napisz cel, nie sposób.** "Klienci skarżą się, że X" jest lepsze niż "zmień linijkę Y".
3. **Przy pieniądzach powiedz to wprost.** Spread, próg antyfraudu (15 000 EUR), zaokrąglenia, salda:
   agent ma zapytać o zgodę i pokazać, ile to kosztuje kantor lub klienta. Jeśli nie zapytał – stop.
4. **Gdy agent pyta, odpowiedz konkretnie.** Przy pieniądzach nie pisz "zrób jak uważasz".
5. **Poproś o wyjaśnienie prostym językiem**, jeśli czegoś nie rozumiesz. To nie strata czasu.

## Codzienna praca krok po kroku

```
git switch main && git pull                     # najnowsza wersja
git switch -c krotka-nazwa-zmiany               # osobna gałąź na zmianę
#   ... praca z agentem, aż testy są zielone ...
git add <pliki>  &&  git commit -m "..."        # agent podpowie pliki i opis
git push -u origin krotka-nazwa-zmiany
```

Na GitHubie: **Compare & pull request → Create pull request**. Poczekaj na 3 zielone sprawdzenia,
przejrzyj zakładkę **Files changed** (patrz niżej) i dopiero wtedy wybierz **Rebase and merge**,
a po scaleniu kliknij **Delete branch**. Potem u siebie:

```
git switch main && git pull
git branch -D krotka-nazwa-zmiany    # -D: po "Rebase and merge" git nie rozpozna gałęzi jako scalonej
```

Jeśli zmiana zawierała nową migrację, uruchom ją sam (agent tego nie robi):

```
docker exec -it php-fpm php bin/console doctrine:migrations:migrate
```

## Czerwony CI – co znaczy

| Sprawdzenie | Czerwone znaczy | Co zrobić |
|---|---|---|
| `Unit tests` | Zmiana psuje działanie aplikacji. | Wklej agentowi wynik: "CI czerwony, napraw przyczynę". |
| `Migrations on MariaDB 11.4` | Nowa migracja nie działa na bazie. | To samo. Nie uruchamiaj tej migracji u siebie. |
| `Committed migrations unchanged` | Ktoś zmienił starą migrację. | Cofnij tę zmianę; zmiana bazy tylko nową migracją. |

Czerwonego pull requesta **nigdy nie scalaj**. GitHub i tak na to nie pozwoli.

## Nie akceptuj zmiany, gdy

- agent zmienił **oczekiwane wyniki w istniejących testach**, usunął albo wyłączył test –
  chyba że wcześniej wytłumaczył Ci dlaczego i się zgodziłeś;
- zmiana dotyka spreadu, progu antyfraudu, zaokrągleń albo sald, **a Ty o to nie prosiłeś**;
- w **Files changed** widzisz:
  - stare pliki w `migrations/` (zmienione, nie nowe),
  - `.env`,
  - `.github/workflows/` (CI),
  - `.claude/settings.json` lub `.claude/hooks/` (zabezpieczenia agenta),
  - `AGENTS.md` (reguły),

  a nie prosiłeś o taką zmianę;
- agent mówi "naprawione", ale nie pokazał wyniku testów;
- agent zmieniał dane w bazie poleceniem SQL (`UPDATE`, `INSERT`, `DELETE`) zamiast przez aplikację
  lub migrację;
- Claude Code pokazał komunikat **"Nie akceptuj tej zmiany"** (testy czerwone mimo wezwania);
- recenzent pieniędzy dał **STOP**, a Ty nie zgodziłeś się wcześniej na tę zmianę.

W razie wątpliwości: nie scalaj, zapytaj agenta "wyjaśnij tę zmianę prostym językiem, co może pójść
źle z pieniędzmi klientów?" albo poproś programistę o przegląd.

## Przegląd zmian w pieniądzach

Claude Code woła recenzenta sam po zmianie w pieniądzach. Jeśli nie widzisz jego werdyktu, napisz:
"sprawdź zmianę recenzentem pieniędzy".

Przy innym AI (Cursor, Copilot, ChatGPT…) zrób to sam, zanim utworzysz pull request. Otwórz **nowy czat**
(recenzent nie powinien znać tłumaczeń autora zmiany) i wklej:

```
Przejrzyj zmiany na tej gałęzi według docs/przeglad-zmian-w-pieniadzach.md. Niczego nie zmieniaj.
```

Przy **STOP** nie scalaj. Wklej werdykt agentowi, który robił zmianę, i poproś o wyjaśnienie prostym językiem.

## Dostęp i hasła

- Token GitHuba z uprawnieniem `workflow` (pozwala zmieniać CI) tylko dla Ciebie. Jeśli kiedyś dasz
  agentowi własny token, to **bez** `workflow` – wtedy GitHub nie pozwoli mu wyłączyć CI.
- Nigdy nie wklejaj agentowi w czacie tokenów, haseł ani zawartości `.env` z serwera produkcyjnego.
- Listy osób z prawem omijania ochrony gałęzi (`Settings → Rules → Rulesets`) nie uzupełniaj –
  także o siebie.

## Gdy coś poszło nie tak po scaleniu

1. Nie poprawiaj danych ręcznie w bazie.
2. Poproś agenta: "cofnij scalony PR #N" – powstanie nowy pull request z cofnięciem (`git revert`),
   który przechodzi przez CI jak każda zmiana.
3. Jeśli zmiana dotknęła sald lub przelewów, sprawdź z agentem (zapytaniami `SELECT`), których
   klientów dotyczy, zanim cokolwiek poprawisz.
