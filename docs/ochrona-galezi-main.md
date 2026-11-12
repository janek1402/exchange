# Ochrona gałęzi `main`

Ochrona gałęzi to ustawienie na GitHubie, nie plik w repo. Ten opis i plik
[`github-ruleset-main.json`](github-ruleset-main.json) pokazują, jak jest ustawiona, żeby dało się to
sprawdzić bez dostępu do ustawień repo. Stan z 2026-11-12.

## Co jest włączone

Ruleset `protect-main` jest **aktywny** i obejmuje domyślną gałąź (`main`):

| Zasada | Co to znaczy |
|---|---|
| Zmiana tylko przez pull request (`pull_request`) | Nikt nie wypchnie zmian prosto na `main`. Scalanie tylko przyciskiem "Rebase and merge". |
| Wymagane sprawdzenia CI (`required_status_checks`) | PR można scalić dopiero, gdy wszystkie 3 sprawdzenia są zielone: "Unit tests", "Migrations on MariaDB 11.4", "Committed migrations unchanged". |
| Zakaz force push (`non_fast_forward`) | Historii `main` nie da się przepisać. |
| Zakaz usunięcia (`deletion`) | Gałęzi `main` nie da się skasować. |

## Czego ta ochrona nie robi

- **Nie wymaga akceptacji drugiej osoby** (`required_approving_review_count: 0`). Właściciel scala sam,
  więc to on decyduje, czy zmiana jest dobra (`docs/praca-z-agentem-ai.md`).
- **Nie wymaga, żeby PR był aktualny względem `main`** (`strict_required_status_checks_policy: false`).
  Jeśli w międzyczasie na `main` weszła inna zmiana, CI PR-a mogło sprawdzić starszą wersję. CI
  uruchamia się jeszcze raz po scaleniu (push na `main`), więc ewentualny błąd będzie widać od razu.
- **Listy osób z prawem omijania ochrony nie widać bez logowania.** GitHub pokazuje ją tylko
  administratorowi repo. Jest pusta (sprawdzone w `Settings → Rules → Rulesets`). Że ochrona działa
  także na administratora, pokazała próba: bezpośredni push na `main` został odrzucony (błąd GH013,
  opisany w `AI-DECISIONS.md`, sekcja c).

## Jak to sprawdzić samemu

Bez logowania, w dowolnym terminalu:

```bash
curl -s https://api.github.com/repos/janek1402/exchange/rules/branches/main
curl -s https://api.github.com/repos/janek1402/exchange/rulesets/26817948
```

Pierwsze polecenie pokazuje zasady obowiązujące teraz na `main`, drugie ruleset, z którego pochodzą.
Wynik powinien zgadzać się z [`github-ruleset-main.json`](github-ruleset-main.json). Jeśli ustawienia
zmienisz na GitHubie, zaktualizuj też ten opis i plik JSON.
