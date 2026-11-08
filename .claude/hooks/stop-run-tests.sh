#!/usr/bin/env bash
# Hook "Stop" dla Claude Code: uruchamia testy, zanim agent zakończy odpowiedź.
#
# - Testy zielone            -> agent może skończyć (exit 0).
# - Testy czerwone           -> agent NIE może skończyć: dostaje wynik testów i musi
#                               je naprawić (exit 2, opis na stderr).
# - Drugi raz z rzędu czerwone (stop_hook_active = true)
#                            -> nie blokujemy ponownie, żeby agent nie kręcił się w pętli
#                               bez końca; właściciel dostaje wyraźne ostrzeżenie.
# - Brak Dockera / kontenera -> ostrzeżenie dla właściciela, bez blokady.
#
# Testy uruchamiamy w kontenerze, tak jak w AGENTS.md (nigdy PHP na hoście).

set -u

CONTAINER="${KANTOR_PHP_CONTAINER:-php-fpm}"

input="$(cat)"

# Bez jq: wystarczy sprawdzić jedno pole logiczne.
stop_hook_active=false
if printf '%s' "$input" | grep -Eq '"stop_hook_active"[[:space:]]*:[[:space:]]*true'; then
    stop_hook_active=true
fi

# Komunikat dla właściciela (wyświetlany w Claude Code), bez blokowania agenta.
warn_user() {
    # $1 to stały tekst bez cudzysłowów i ukośników, więc można go wstawić do JSON wprost.
    printf '{"systemMessage": "%s"}\n' "$1"
    exit 0
}

if ! command -v docker >/dev/null 2>&1; then
    warn_user "UWAGA: nie znaleziono Dockera, testy NIE zostały uruchomione. Uruchom je ręcznie: docker exec ${CONTAINER} composer tests"
fi

if [ "$(docker inspect -f '{{.State.Running}}' "$CONTAINER" 2>/dev/null)" != "true" ]; then
    warn_user "UWAGA: kontener ${CONTAINER} nie działa, testy NIE zostały uruchomione. Uruchom Dockera (docker compose up -d) i testy: docker exec ${CONTAINER} composer tests"
fi

output="$(docker exec "$CONTAINER" composer tests 2>&1)"
status=$?

if [ "$status" -eq 0 ]; then
    exit 0
fi

if [ "$stop_hook_active" = true ]; then
    warn_user "UWAGA: testy są CZERWONE, a agent nie naprawił ich po pierwszym wezwaniu. Nie akceptuj tej zmiany. Sprawdź wynik: docker exec ${CONTAINER} composer tests"
fi

{
    echo "Testy nie przechodzą (docker exec ${CONTAINER} composer tests, kod ${status})."
    echo "Nie kończ pracy. Napraw przyczynę w kodzie zgodnie z AGENTS.md: nie zmieniaj oczekiwań"
    echo "w istniejących testach, nie usuwaj i nie pomijaj testów. Jeśli uważasz, że test jest błędny,"
    echo "zatrzymaj się i wyjaśnij to właścicielowi zamiast go zmieniać."
    echo
    echo "Ostatnie linie wyniku testów:"
    # Bez kolorów ANSI, ostatnie 60 linii wystarczy, żeby zobaczyć porażki i podsumowanie.
    printf '%s\n' "$output" | sed 's/\x1b\[[0-9;]*m//g' | tail -n 60
} >&2
exit 2
