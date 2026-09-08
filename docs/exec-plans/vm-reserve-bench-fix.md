# ExecPlan: poprawny zapis ławki rezerwowych w VM

## Status i cel

2026-09-07 — plan naprawy; bez implementacji. Zgłoszenie potwierdza, że po wybraniu i wysłaniu **Wariantu planu zmian** integracja umieszcza na ławce zawodników z najniższymi paskami zamiast zawodników wskazanych przez `substitution_player` tego wariantu. Celem jest, aby ta akcja jednym pełnym payloadem taktyki zapisała w VM starterów oraz dokładnie rezerwowych wymaganych przez wybrany wariant, a następnie wysłała odpowiadające mu definicje zmian.

Objaw jest odtworzalny na granicy payloadu: `benchVmPlayerIds` ignoruje plan i sortuje całą pulę rosnąco po `training_bar`. Nie wykonywano zapisu na zdalnym VM. Pełny cykl reprodukcja → naprawa → regresja pozostaje zadaniem implementacyjnym, zgodnie z żądaniem planowania.

## Źródła

- Zgłoszenie użytkownika i wywołany `terra-feature-plan`.
- Zrzut użytkownika z narzędzi przeglądarki: obserwowany `POST /api/tactics` zawiera `matchType`, `matchId`, bloki i wszystkie `player1`–`player12`; obecny przepływ nie zapisuje ławki osobnym żądaniem.
- `AGENTS.md`, `CONTEXT.md`, `docs/agents/domain.md`, `docs/exec-plans/TEMPLATE.terra-luna.md`; brak ADR.
- `app/VmTacticsService.php`: `buildPayload`, `starterVmPlayerIds`, `benchVmPlayerIds`, `pushRecommendation`.
- `app/VmSubstitutionService.php`: `buildPayloads`, `pushPlan`, `innerPlan`.
- `app/Packages/VmManagerApi/Services/VmManagerApiService.php`: `getTactics`, `saveTactics`, `listTacticsChanges`.
- `resources/views/pages/optimizer/⚡result.blade.php`: `confirmPushLineup`, `pushSubstitutions`, `lineupRecommendations`, `rankedPlans`.
- `tests/Unit/VmTacticsServiceTest.php`, `tests/Unit/VmSubstitutionServiceTest.php`, `tests/Feature/LineupTacticsPushTest.php`, `tests/Feature/VmConnectionAndSubstitutionsTest.php`.
- Historia: `8d2b475` (integracja), `e4b6c4d` (refaktoryzacja integracji).
- `TASKS.md:128`, `:177`, `:187`: historyczne zadania i otwarte decyzje o pełnej ławce; część dokumentu jest starsza niż obecny kod. `CONTEXT.md` ma pierwszeństwo w terminologii scenariuszy.

## Stan obecny i granice dowodów

1. `confirmPushLineup` przekazuje rekomendację składu i wszystkich dostępnych graczy do `VmTacticsService`. Nie przekazuje wybranego wariantu planu zmian.
2. `starterVmPlayerIds` mapuje sześciu zawodników i libero na `player1`–`player7`. Ławka zajmuje `player8`–`player12`. Zrzut rzeczywistego żądania potwierdza, że oba segmenty są wysyłane w tym samym payloadzie taktyki wraz z blokami, a nie przez osobny zapis ławki.
3. `benchVmPlayerIds` odrzuca starterów i nieprawidłowe ID VM, sortuje pozostałych globalnie po `training_bar`, `name`, `id`, a następnie bierze pięciu. Nie uwzględnia pozycji ani uczestnictwa w zmianach.
4. `pushSubstitutions` wysyła wskazany wariant do `VmSubstitutionService::pushPlan`. Budowanie definicji zmian poprawnie mapuje lokalne ID dokładnego `substitution_player` na ID VM, ale nie zapewnia jego obecności na ławce.
5. `lineupRecommendations` powstaje niezależnie od `rankedPlans`. Rekomendacja zmian jest przypisana do konkretnego scenariusza wyniku; nie wolno mieszać zawodników z różnych wariantów lub scenariuszy.
6. Test ławki wprost oczekuje pięciu najniższych pasków. Zielony wynik tego testu nie potwierdza poprawności kontraktu z VM ani zgodności z definicjami zmian.
7. Widok wyniku (`:764`) informuje o ręcznym ustawieniu starterów i rezerwowych zgodnie z wariantem. Połączenie obu akcji byłoby zmianą dotychczasowego zachowania, a nie wyłącznie korektą serializacji ławki. `tests/Unit/VmSubstitutionServiceTest.php` zawiera tylko test przykładowy; nie traktować jego zielonego wyniku jako pokrycia serwisu.

Minimalny przypadek do odtworzenia: pełny skład, sześciu kandydatów na ławkę i zmiana wykorzystująca kandydata z najwyższym paskiem. Obecny dobór pomija go w `player8`–`player12`. Osobno należy sprawdzić, czy VM przypisuje tym polom konkretne pozycje — nie zakładać tego na podstawie numerów pól.

## Decyzje architektoniczne

- Utrzymać podział: `VmTacticsService` buduje skład, `VmSubstitutionService` buduje i wysyła definicje zmian, klient API odpowiada za transport, komponent za wybór i komunikaty.
- Naprawić najwęższy potwierdzony problem. Nie przebudowywać algorytmu optymalizacji, uwierzytelniania ani całego interfejsu przy okazji.
- Kontrakt zewnętrzny, kolejność i znaczenie miejsc ławki muszą wynikać z dowodu: kodu rzeczywistego odbiorcy albo zanonimizowanego żądania poprawnego ręcznego zapisu i odczytu. Test lokalnego klienta nie stanowi takiego dowodu.
- Bez migracji, nowych zależności i zmian publicznych tras. Identyfikatory lokalne i VM pozostają rozdzielone.
- Ławka dla wybranego Wariantu planu zmian pochodzi wyłącznie z faktycznych `substitution_player` tego wariantu, w deterministycznej kolejności pierwszego wystąpienia w planie. Deduplikacja jest po ID VM; zawodnik z boiska nie może jednocześnie zajmować ławki. Nie wolno uzupełniać jej zawodnikami dobranymi po `training_bar`.
- Nie obcinać po cichu wymaganych zawodników po przekroczeniu pięciu miejsc. Zgłosić błąd przed pierwszym zdalnym zapisem; analogicznie dla brakujących, niedostępnych, niepoprawnie zmapowanych lub obecnych już na boisku zawodników. Niewykorzystane miejsca są jawnie puste tylko po potwierdzeniu reprezentacji pustej wartości przez VM.
- Zwykłe `Wyślij skład` nie ma wybranego Wariantu planu zmian, dlatego nie może wyliczać ławki według pasków. Ma zachować aktualną ławkę odczytaną z VM (lub puste miejsca, gdy nie istnieje). Akcja wybranego wariantu buduje **jeden pełny** payload `POST /api/tactics`: niezmienione `player1`–`player7` i bloki z odczytanej taktyki, a `player8`–`player12` z wariantu. Nie podstawiać niezależnej rekomendacji składu za starterów wariantu.
- Akcja wybranego wariantu jest sekwencją: pełna walidacja i rozwiązywanie ID → odczyt bieżącej taktyki → walidacja zgodności jej starterów z `playerOut` planu → **jeden POST pełnej taktyki `player1`–`player12`** → zapis zmian. Błąd przed zapisem nie wywołuje HTTP POST; błąd po zapisie taktyki jest komunikowany jako częściowy wynik. Brak transakcji między endpointami; zachować istniejące zasady deduplikacji i ponownego wysłania zmian.

## Zależności i zadania

`T1 → T2 → T3 → T4 → T5`.
Zadania implementacyjne są sekwencyjne; pliki testowe i komponent nie mają współbieżnych właścicieli.

### T1 — ustalić kontrakt snapshotu taktyki VM

- Rola: `code_explorer` do dowodów tylko do odczytu; root do decyzji; `test_runner` do reprodukcji.
- Cel: potwierdzić format odpowiedzi `GET /api/tactics` dla `player1`–`player12`, semantykę miejsc `player8`–`player12` oraz reprezentację pustych miejsc przed zmianą serializacji.
- Zakres: wskazane źródła, zanonimizowany odczyt prawidłowej taktyki lub kod rzeczywistego odbiorcy, istniejące testy integracyjne.
- Zależności: brak.
- Akceptacja: normalizacja obu formatów spotykanych w kodzie/testach (`idPlayerN` i `playerN`) ma test, znaczenie miejsc ławki i pustych wartości jest udokumentowane, a przypadek z wymaganym zawodnikiem spoza pięciu najniższych jest czerwony przed naprawą.
- Weryfikacja: porównać zanonimizowany ręczny zapis i odczyt, jeśli są dostępne; nie wykonywać zapisu do zewnętrznego VM bez osobnej autoryzacji.
- Definition of done: dowody i końcowy kontrakt root zapisane w tym planie; ustalone sygnatury i właściciele T2–T4.

### T2 — wyprowadzić wymaganych rezerwowych z wybranego wariantu

- Rola: `laravel_worker` po domknięciu kontraktu T1.
- Cel: udostępnić jedną zwalidowaną reprezentację zmian, z której można pobrać unikalne VM ID `playerIn` wybranego wariantu, bez duplikowania mapowania lokalnych ID w serwisie taktyki.
- Zakres własności: `app/VmSubstitutionService.php`, `tests/Unit/VmSubstitutionServiceTest.php`. Klient API tylko jeśli dowód T1 wskazuje błąd transportu; wtedy root rozszerza zakres przed delegacją.
- Zależności: T1.
- Akceptacja: dokładne `playerIn` i `playerOut` pochodzą tylko z wybranego wariantu; są dodatnimi, unikalnymi ID VM oraz zachowują kolejność pierwszego wystąpienia. Brak ID, niedostępność, starter jako rezerwowy i więcej niż pięć wymaganych rezerwowych kończą się błędem przed HTTP.
- Weryfikacja: realne testy jednostkowe zamiast obecnego testu przykładowego: plan bez zmian, ten sam zmiennik w wielu setach, wymagany zawodnik z wysokim paskiem, nieprawidłowe ID, duplikaty i limit pięciu.
- Definition of done: nowy kontrakt przygotowania zmian jest jedynym źródłem danych dla istniejącego payloadu zmian i T3; formatter i testy zielone.

### T3 — zapisać ławkę zgodną z wybranym wariantem

- Rola: `laravel_worker` po T2, przy zatwierdzonym przez root kontrakcie T1.
- Cel: zastąpić globalny wybór najniższych pasków jednym deterministycznym payloadem pełnej taktyki, którego ławka wynika z wariantu, a starterzy i bloki z bieżącej taktyki.
- Zakres własności: `app/VmTacticsService.php`, `tests/Unit/VmTacticsServiceTest.php`. Ewentualne dostosowanie odczytu w `VmManagerApiService` dopiero po dowodzie T1 i wyłącznie po zatwierdzeniu root.
- Zależności: T1, T2.
- Akceptacja: pojedynczy `POST /api/tactics` zawiera `player1`–`player12`; `player8`–`player12` zawiera dokładnie rezerwowych wybranego wariantu, w tym zawodnika spoza pięciu najniższych pasków; starterzy, libero, bloki oraz typ i ID meczu są zachowane. Zwykły zapis składu nie wybiera już ławki po paskach.
- Weryfikacja: testy pełnego payloadu z pełną i krótszą ławką, obu formatów snapshotu, remisów pasków, braku ID, duplikatów, zawodnika na boisku i limitu pojemności; asercje `player1`–`player7` i bloków pozostają niezmienione.
- Definition of done: regresja T1 przechodzi, nie ma już testu ani implementacji kodującej „pięciu najniższych pasków”, formatter i testy zielone.

### T4 — zorkiestrować wysyłkę wariantu oraz komunikaty

- Rola: `laravel_worker` po T3.
- Cel: akcja `pushSubstitutions` ma wysłać tylko wybrany Wariant planu zmian i wykonać kolejność walidacja → jeden pełny zapis taktyki → zmiany, z jednoznacznym wynikiem częściowej porażki.
- Zakres własności: część PHP i teksty `resources/views/pages/optimizer/⚡result.blade.php`, `tests/Feature/LineupTacticsPushTest.php`, `tests/Feature/VmConnectionAndSubstitutionsTest.php`.
- Zależności: T1–T3.
- Akceptacja: brak mieszania scenariuszy i wariantów; brak POST po błędzie przygotowania lub zgodności starterów; po błędzie zmian interfejs ujawnia, że ławka została zapisana; komunikat i modal nie obiecują już ławki z najniższych pasków.
- Weryfikacja: `Http::fake` sprawdza kolejność GET taktyki → **jeden** POST taktyki zawierający zachowane `player1`–`player7`, bloki i wymaganego rezerwowego z wysokim paskiem w `player8`–`player12` → GET zmian → POST zmian, pełny payload, ponowienie, błąd API oraz autoryzację.
- Definition of done: właściwy wariant i jego rezerwowi są sprawdzeni na granicy HTTP, komunikaty i autoryzacja zachowane, a komponent nie rekonstruuje wariantu po indeksie w sposób mieszający scenariusze.

### T5 — niezależna weryfikacja

- Rola: `test_runner`, potem `reviewer`; root integruje wynik.
- Cel: potwierdzenie naprawy i braku regresji.
- Zakres: zmienione pliki i cztery zestawy testów integracji wymienione niżej.
- Zależności: T2–T4.
- Akceptacja: wszystkie kryteria mają asercje; oryginalny przypadek z T1 działa; brak przypadkowych zmian bazy testowej, danych, zależności i uwierzytelniania.
- Weryfikacja: komendy poniżej oraz review payloadów, limitu ławki i zachowania przy błędach.
- Definition of done: root potwierdza dowody testowe i ograniczenia sprawdzenia zdalnego; plan oznaczony jako wdrożony dopiero po implementacji.

## Kryteria akceptacji i testy

| Kryterium | Dowód | Zadanie |
| --- | --- | --- |
| Właściwe pola i znaczenie miejsc ławki | Kontrakt odbiorcy + test payloadu | T1, T3 |
| Rezerwowi są dokładnie `substitution_player` wybranego wariantu, bez duplikatów i starterów | Asercje przygotowania planu i pełnej ławki | T2, T3 |
| Jeden payload zachowuje szóstkę, libero i bloki oraz zapisuje właściwą ławkę | Asercje `player1`–`player12` i bloków w jednym POST | T3, T5 |
| Zgodność ławki oraz definicji zmian z tym samym wariantem i scenariuszem | Test akcji komponentu na granicy HTTP | T4 |
| Brak fałszywego sukcesu i zapisów po błędzie walidacji | Testy błędów i kolejności wywołań | T2–T4 |
| Zachowane typy meczu i ponowienia | Istniejące testy i regresje | T4, T5 |

Komendy (uruchamiać minimalny zestaw adekwatny do zmian):

```sh
vendor/bin/sail artisan test --compact tests/Unit/VmTacticsServiceTest.php
vendor/bin/sail artisan test --compact tests/Feature/LineupTacticsPushTest.php tests/Feature/VmConnectionAndSubstitutionsTest.php tests/Unit/VmSubstitutionServiceTest.php
vendor/bin/pint --dirty --format agent
```

Baseline wykonany 2026-09-07: pierwsza komenda 4 testy / 49 asercji; druga 11 testów / 57 asercji, wszystkie zielone. Lokalny `php artisan test` nie działa z powodu braku sterownika SQLite; Sail działa. Testy modyfikują śledzony plik SQLite `testing`; podczas planowania przywrócono jego pierwotną zawartość po uprzednim potwierdzeniu czystego drzewa. Przy kolejnych uruchomieniach zachować ewentualne istniejące zmiany użytkownika.

## Zgodność, wdrożenie i ryzyka

- Brak migracji i nowych pakietów. Naprawa dotyczy kolejnego wysłania; nie naprawia automatycznie już zapisanej taktyki.
- Nie usuwać istniejących definicji zmian w VM przy okazji. Mogą dotyczyć innych ustawień; zgodność całej historycznej listy nie jest tym samym co zgodność wysyłanego wariantu.
- Dane zawodników mogą zmienić się od wyliczenia wariantu do wysyłki: sprawdzać dostępność i ID przy akcji.
- Indeksy `rankedPlans` są rozwiązywane na podstawie ponownie obliczanych danych. T4 ma sprawdzić zmianę kolejności po aktualizacji pasków i, jeśli jest możliwa w jednej akcji Livewire, przekazać trwały podpis wariantu zamiast polegać na samym indeksie.
- Limit pięciu rezerwowych i role miejsc to obecne założenia klienta wymagające potwierdzenia po stronie rzeczywistego VM.
- Obserwowany przepływ nie używa osobnego zapisu ławki: korekta `player8`–`player12` ponownie zapisuje także starterów. Z tego powodu niepełny lub niezgodny snapshot bieżącej taktyki musi zatrzymać operację przed POST.
- Zapis składu i zmian nie jest atomowy; nie obiecywać rollbacku.
- Testy z atrapą HTTP dowodzą zawartości żądań, nie zdalnego utrwalenia. Jeżeli potrzebny będzie zdalny dowód, użyć autoryzowanego środowiska i sprawdzić odczyt po zapisie.

## Otwarte pytania

1. Czy rzeczywisty VM wymaga konkretnych pozycji w `player8`–`player12` i jak reprezentuje puste miejsca?
   Wskazany w środowisku katalog `/home/kuba/projects/VM` nie istnieje. Znalezienie innego projektu o nazwie volleyball-manager nie potwierdza, że zawiera odbiorcę skonfigurowanego API. Kontrakt zdalnej ławki pozostaje niezweryfikowany w tym planie.
2. Czy aktualna taktyka w VM zawsze zawiera wszystkie siedem starterów podczas wysyłania wariantu? Przy braku pełnego snapshotu implementacja musi odmówić zapisu zamiast nadpisywać skład niepełnymi danymi.
