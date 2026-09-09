# Optymalizacja UI wariantów składu i ławki rezerwowych

This ExecPlan is a living document. The sections `Progress`, `Surprises & Discoveries`, `Decision Log`, and `Outcomes & Retrospective` must be kept up to date as work proceeds.

This plan must be maintained in accordance with `.agent/PLANS.md` at the repository root.

## Purpose / Big Picture

Po wdrożeniu użytkownik zobaczy skład podstawowy, dokładnie pięć miejsc ławki rezerwowych i reguły zmian należące do aktualnie wybranego Wariantu planu zmian. Zniknie myląca, niezależna sekcja „Propozycja składu”; lista Wariantów planu zmian będzie służyła do wyboru, a jeden panel szczegółów pokaże wybrany Wariant bez powielania całych składów w każdej karcie. Warianty bez żadnej rzeczywistej zmiany nie będą obliczane ani prezentowane, a ekran zablokuje wysyłkę, gdy pełnej siódemki albo limitu pięciu miejsc ławki nie da się legalnie zbudować.

## Scope Freeze

In scope:
- Przebudowa `resources/views/pages/optimizer/⚡result.blade.php` tak, aby usuwała sekcję „Propozycja składu”, jej przyciski i modal, a zachowywała wybór typu meczu oraz usuwanie wszystkich zmian.
- Zbudowanie dla każdego Wariantu planu zmian pełnej siódemki: starterzy pozycji analizowanych przez optymalizator oraz deterministyczna rekomendacja bazowa dla pozycji nieanalizowanych.
- Zbudowanie ławki Wariantu jako pięciu stałych miejsc (`player` albo `null`), z unikalnymi zawodnikami wynikającymi z `substitution_player`, bez starterów i bez cichego obcinania nadmiaru.
- Grupowanie listy według Scenariusza wyniku meczu, stabilny klucz Wariantu, wybór domyślny pierwszej Rekomendacji zmian oraz jeden dynamiczny panel szczegółów.
- Odfiltrowanie Wariantów bez żadnej rzeczywistej zmiany oraz wysyłka każdego pozostałego poprawnego Wariantu przez istniejące `VmTacticsService::pushVariantTactics` i `VmSubstitutionService`.
- Testy Pest pokrywające dane pełnego Wariantu, ławkę pięciu miejsc, wybór, blokadę niepełnego Wariantu i wysyłkę.
- Aktualizacja `CONTEXT.md` i utrzymywanie tego planu jako źródła decyzji implementacyjnych.

Out of scope:
- Zmiana algorytmu oceniania, generowania scenariuszy wyniku meczu lub liczby trzech Wariantów na Scenariusz.
- Implementacja ręcznego wymuszenia konkretnego zawodnika na pozycji. Przyszłe wymuszenie ma wejść jako ograniczenie wejściowe optymalizacji i obowiązywać we wszystkich Wariantach, nie jako podmiana po obliczeniu.
- Odczyt bieżącej taktyki z VM jako źródło składu bazowego.
- Zmiana publicznych endpointów API, formatu autoryzacji albo migracji bazy danych.
- Zwiększenie ławki ponad pięć miejsc, ciche obcinanie zawodników lub obliczanie nielegalnych Wariantów.
- Usuwanie wewnętrznej implementacji `LineupRecommendationService`/alternatyw, jeżeli nie jest potrzebne do kontraktu UI; alternatywy nie mogą jednak trafić do stanu ani renderu wyniku.

## API/UI Contract Snapshot

Ta zmiana nie dodaje endpointu HTTP. Livewire pozostaje właścicielem stanu strony wyniku, a istniejące usługi VM pozostają granicą zapisu.

- Stan Livewire `rankedPlans`:
  - każdy wpis zawiera `variant_key`, `scenario_label`, `scenario_input`, `scenario_sets_count`, `scenario_rank`, metryki optymalizacji oraz surowy `plan`;
  - dane pochodne Wariantu muszą obejmować `lineup` z siedmioma kluczami `VmTacticsService::COURT_SLOT_KEYS`, `bench` z dokładnie pięcioma elementami (`player` albo `null`), `missing_slots` i `is_sendable`;
  - `variant_key` musi być deterministyczny na podstawie Scenariusza oraz starterów i zmian, a nie indeksu tablicy.
- Stan wyboru:
  - `selectedVariantKey` wskazuje jeden stabilny `variant_key`;
  - domyślnie wybierana jest pierwsza Rekomendacja zmian pierwszego Scenariusza wyniku meczu;
  - po ponownym obliczeniu, gdy klucz zniknie, wybór wraca do pierwszego poprawnego Wariantu.
- Panel szczegółów:
  - pokazuje pełną siódemkę, pięć miejsc ławki, metryki, Scenariusz wyniku meczu, reguły per set oraz status brakujących pozycji;
  - przycisk wysyłki jest dostępny tylko dla `is_sendable === true`; wszystkie prezentowane Warianty mają co najmniej jedną rzeczywistą zmianę.
- Zapis taktyki:
  - wywołuje `VmTacticsService::pushVariantTactics($substitutionPayloads, $starterVmPlayerIds, $matchType, $matchId)`;
  - wysyła siedem starterów oraz maksymalnie pięciu unikalnych rezerwowych; plan bez żadnej zmiany nie trafia do tego kroku;
  - następnie przekazuje przygotowane payloady do `VmSubstitutionService::pushPreparedPayloads`; pusta lista payloadów oznacza sukces bez tworzenia reguł zmian.
- Błędy:
  - brak pozycji bazowej, duplikat startera, zawodnik poza dostępną pulą albo ławka większa niż pięć oznaczają `is_sendable === false` i czytelny komunikat w UI;
  - błędy usługi VM są obsługiwane obecnym mechanizmem toastów/komunikatów Livewire i nie zmieniają wybranego Wariantu.

## Progress

- [x] (2026-09-09) Przeanalizować komponent wyniku, optymalizator oraz integrację zapisu VM.
- [x] (2026-09-09) Uzgodnić terminologię i decyzje domenowe w `CONTEXT.md`.
- [ ] Przygotować wspólny model pełnego Wariantu, stabilny wybór i stałą ławkę pięciu miejsc.
- [ ] Usunąć ogólną „Propozycję składu” i zbudować listę Scenariuszy oraz dynamiczny panel szczegółów.
- [ ] Odfiltrować Warianty bez zmian i podłączyć wysyłkę wybranego Wariantu.
- [ ] Dodać/zmienić testy i przeprowadzić walidację focused oraz regresyjną.
- [ ] Uzupełnić ten plan o wynik wdrożenia i retrospektywę.

## Surprises & Discoveries

- `TrainingOptimizerService` generuje Warianty tylko dla pozycji objętych analizą; plan nie zawiera sam z siebie pełnej siódemki. Dowód: `scenarioRankings()` przekazuje `slotDefinitions`, a każdy `plan['slots']` reprezentuje wyłącznie te sloty.
- Obecny panel „Szczegóły najlepszego wariantu” zawsze bierze `$this->rankedPlans[0]`, więc nie odzwierciedla wyboru Scenariusza ani Wariantu.
- Istniejący `starterVmPlayerIdsForPlan()` nakłada starterów Wariantu na ukrytą główną rekomendację. Nowa implementacja powinna najpierw zbudować jeden jawny model pełnego Wariantu i używać go zarówno w renderze, jak i w wysyłce.
- `resources/views/pages/optimizer/⚡create.blade.php` już odrzuca sumę limitów rezerwowych większą niż pięć, a limit `0` nadal pozwala na startera i wyłącza kandydatów zmian.
- Aktualny helper `variantBenchPlayers()` zwraca tylko zawodników występujących jako `substitution_player`; przyszły render musi opakować wynik w pięć pozycji z pustymi miejscami, zamiast traktować zmienną długość jako ławkę VM.
- Skupione testy dla wyniku i zapisu przechodzą. Pełny `OptimizerFlowTest.php` ma istniejącą, niezwiązaną porażkę testu blokady zawodnika w nieoptymalizowanym slocie (`tests/Feature/OptimizerFlowTest.php:208`); nie należy naprawiać jej w ramach tego UI.

## Decision Log

- Decision: Usunąć całą niezależną sekcję „Propozycja składu” z ekranu wyniku. Rationale: nie jest własnością żadnego Scenariusza i może pokazywać innych starterów niż wysyłany Wariant. Date/Author: 2026-09-09 / użytkownik i agent.
- Decision: Każdy Wariant pokazuje pełną siódemkę. Rationale: użytkownik musi widzieć dokładnie to, co zostanie zapisane; pozycje nieanalizowane dostają rekomendację bazową. Date/Author: 2026-09-09 / użytkownik i agent.
- Decision: Skład bazowy pochodzi z najniższych pasków treningowych, nie z bieżącej taktyki VM. Rationale: zachowuje obecny cel optymalizacji i nie wprowadza ukrytego źródła danych. Date/Author: 2026-09-09 / użytkownik i agent.
- Decision: Lista Wariantów plus jeden dynamiczny panel szczegółów. Rationale: zachowuje porównywanie bez powielania siódemki i ławki w każdej karcie. Date/Author: 2026-09-09 / użytkownik i agent.
- Decision: Wariant z `substitutions_count === 0` nie jest obliczany ani prezentowany; brak zmiany w pojedynczym slocie pozostaje dozwolony. Rationale: wynik optymalizacji ma opisywać realny plan zmian, ale limit `0` dla pojedynczej pozycji nie powinien usuwać startera. Date/Author: 2026-09-09 / użytkownik i agent.
- Decision: Wysyłka jest dostępna dla każdego poprawnego, zaprezentowanego Wariantu planu zmian. Rationale: każdy pokazany Wariant zawiera co najmniej jedną rzeczywistą zmianę, więc zapis taktyki i reguł zmian jest spójny. Date/Author: 2026-09-09 / użytkownik i agent.
- Decision: Ławka ma zawsze pięć możliwych miejsc; Wariant wymagający więcej jest nielegalny. Rationale: odpowiada ograniczeniu VM i nie ukrywa utraty zawodników przez truncation. Date/Author: 2026-09-09 / użytkownik i agent.
- Decision: Limit rezerwowych `0` pozostawia startera, ale nie generuje rezerwowego ani zmiany dla pozycji. Rationale: limit dotyczy ławki, nie obsady na boisku. Date/Author: 2026-09-09 / użytkownik i agent.
- Decision: Przyszłe ręczne wskazanie zawodnika będzie ograniczeniem przed optymalizacją. Rationale: musi wpływać spójnie na wszystkie Warianty, a nie być naprawą jednego wyniku po fakcie. Date/Author: 2026-09-09 / użytkownik i agent.

## Outcomes & Retrospective

Planowanie zakończone: zakres, model domenowy, kontrakt UI i sytuacje brzegowe są rozstrzygnięte. Implementacja nie jest częścią tej fazy. Po wykonaniu planu należy odnotować, czy jeden model pełnego Wariantu rzeczywiście wyeliminował rozjazdy między renderem a payloadem VM oraz czy użytkownik bez dodatkowego objaśnienia rozumie wybór Scenariusza i Wariantu.

## Context and Orientation

Repozytorium jest aplikacją Laravel z komponentem Livewire/Volt dla optymalizacji zmian. Kod widoku wyniku i stan Livewire znajdują się w jednym pliku SFC; usługi domenowe i VM są zwykłymi klasami PHP.

- `resources/views/pages/optimizer/⚡result.blade.php`: komponent wyniku; zawiera `scenarioRankings()`, `rankedPlans()`, wysyłkę oraz obecne sekcje UI.
- `resources/views/pages/optimizer/⚡create.blade.php`: formularz wejściowy; waliduje limity rezerwowych, w tym sumę maksymalnie pięciu.
- `app/TrainingOptimizerService.php`: oblicza rankingi Wariantów zmian dla każdego Scenariusza wyniku meczu.
- `app/LineupRecommendationService.php`: wybiera bazową rekomendację pełnej siódemki z dostępnych zawodników według pasków treningowych.
- `app/VmTacticsService.php`: definiuje siedem kluczy boiska (`COURT_SLOT_KEYS`) i zapisuje starterów oraz rezerwowych do VM.
- `app/VmSubstitutionService.php`: tłumaczy lokalne `starter_player`/`substitution_player` na payloady zmian i wysyła je z deduplikacją.
- `tests/Feature/OptimizerFlowTest.php`: testy renderu, rankingu i danych komponentu.
- `tests/Feature/LineupTacticsPushTest.php`: testy wysyłki pełnej taktyki.
- `tests/Feature/VmConnectionAndSubstitutionsTest.php` oraz `tests/Unit/VmTacticsServiceTest.php`: testy mapowania i kontraktu VM.
- `CONTEXT.md`: uzgodnione słownictwo: Scenariusz wyniku meczu, Wariant planu zmian, Rekomendacja zmian, pełna siódemka i pięć miejsc ławki.

Wariant planu zmian to jedna dopuszczalna kombinacja starterów i co najmniej jednej reguły zmian w obrębie jednego Scenariusza wyniku meczu. „Pełny Wariant” oznacza, że do slotów analizowanych dodano bazowych starterów wszystkich pozostałych pozycji. „Ławka” oznacza pięć pozycji VM, nie dowolnie długą listę kandydatów.

## Plan of Work

### Milestone 1 — Jawny model pełnego Wariantu

W `resources/views/pages/optimizer/⚡result.blade.php` wydzielić obliczenie danych pochodnych Wariantu. Najpierw pobrać jedną bazową rekomendację pełnej siódemki, zmapować ją po jawnych kluczach `COURT_SLOT_KEYS`, a następnie nałożyć starterów slotów Wariantu według pozycji i `slot_number`. Odrzucić duplikaty i oznaczyć brakujące pozycje. Zbudować stabilny `variant_key` z identyfikatora Scenariusza oraz podpisu starterów i zmian. Zmienić helper ławki tak, aby zwracał dokładnie pięć pozycji, wykluczał wszystkich starterów i zgłaszał stan nielegalny, zamiast obcinać szóstego zawodnika. Dodać computed/helper dla wybranego klucza i bezpiecznego domyślnego wyboru.

Na końcu milestone dane używane przez UI i wysyłkę muszą pochodzić z tego samego modelu. Wariant z nieobsadzoną pozycją bazową albo więcej niż pięcioma rezerwowymi pozostaje widoczny diagnostycznie, ale ma `is_sendable === false`.

### Milestone 2 — Lista Scenariuszy i jeden panel szczegółów

W tym samym komponencie usunąć cały render „Propozycja składu”, w tym `requestPushLineup`, `confirmPushLineup`, modal i zależne komunikaty, o ile nie są używane przez nowy panel. Zachować globalne akcje strony. Przebudować sekcję Top warianty na grupy Scenariuszy wyniku meczu, z kartami zawierającymi metryki i kontrolką wyboru. Wybrany klucz ma aktualizować jeden dynamiczny panel.

Panel ma renderować kolejno: nazwę Scenariusza i rangę, pełną siódemkę w ustalonej kolejności boiska, pięć numerowanych miejsc ławki z pustymi polami, sumę/metryki, a następnie reguły zmian per set. Nie renderować alternatywnych rekomendacji jako osobnej funkcji. Przed przypisaniem rangi odfiltrować plany z `substitutions_count === 0`. Karty i panel muszą poprawnie działać, gdy Scenariusz ma zero legalnych Wariantów albo gdy wybrany Wariant jest niekompletny.

### Milestone 3 — Wysyłka wybranego Wariantu

Przenieść akcję wysyłki do panelu szczegółów. Przed HTTP sprawdzić `is_sendable`, kompletność siedmiu starterów, unikalność VM ID i maksymalnie pięć rezerwowych. Z istniejącego planu przygotować payloady zmian przez `VmSubstitutionService::buildPayloads`, następnie zapisać pełną taktykę przez `VmTacticsService::pushVariantTactics`, a na końcu wykonać `pushPreparedPayloads`. Ponieważ plany bez zmian są odfiltrowane, każda wysyłka ma co najmniej jedną regułę; nie tworzyć sztucznych reguł.

Wszystkie komunikaty, blokady przycisku i loading state muszą dotyczyć wybranego `variant_key`, nie indeksu płaskiej tablicy. Po błędzie wybrany Wariant pozostaje zaznaczony, aby można było ponowić operację.

### Milestone 4 — Testy i zamknięcie

Zaktualizować testy Pest pod nowy kontrakt. Dodać asercje, że render nie zawiera „Alternatywy” ani „Propozycji składu”, że każdy Wariant ma siedem slotów i pięć miejsc ławki, że pozycja z limitem `0` zachowuje startera, że brak bazowej pozycji blokuje wysyłkę oraz że plan bez zmian jest odfiltrowany. Zachować istniejące testy mapowania VM. Uruchomić Pint i focused testy, a potem odpowiedni zestaw regresyjny.

## Parallel Work Map

Parallel-safe tracks:
- Po ustaleniu modelu z Milestone 1 można równolegle przygotować test fixtures/asercje w `tests/Feature/OptimizerFlowTest.php` oraz testy zapisu w `tests/Feature/LineupTacticsPushTest.php`, pod warunkiem że nie zmieniają wspólnego kodu komponentu.
- Po stabilizacji renderu można niezależnie uruchomić test runner dla testów VM i rankingu.

Blocking dependencies:
- Milestone 2 czeka na stabilny kształt pełnego Wariantu, `variant_key`, `bench` i `is_sendable` z Milestone 1.
- Milestone 3 czeka na wspólną funkcję mapującą pełny lineup i ławkę; wysyłka nie może odtwarzać tych danych osobno.
- Testy wysyłki czekają na finalne nazwy akcji Livewire i kształt stanu wyboru.
- Nie uruchamiać równoległych workerów zapisujących ten sam plik `⚡result.blade.php`.

## Concrete Steps

Wykonywać z katalogu repozytorium `/home/kuba/projects/vm-manager-subs-optimizer`.

    cd /home/kuba/projects/vm-manager-subs-optimizer
    sed -n '1,260p' CONTEXT.md
    rg -n "lineupRecommendations|rankedPlans|scenarioRankings|pushVariantTactics" resources/views/pages/optimizer/⚡result.blade.php app tests

Po każdym milestone:

    vendor/bin/pint --dirty --format agent
    vendor/bin/sail artisan test --compact tests/Feature/OptimizerFlowTest.php --filter='optimizer result page'

Po ukończeniu implementacji:

    vendor/bin/sail artisan test --compact tests/Feature/OptimizerFlowTest.php tests/Feature/LineupTacticsPushTest.php tests/Feature/VmConnectionAndSubstitutionsTest.php tests/Unit/VmTacticsServiceTest.php
    vendor/bin/pint --dirty --format agent

Jeżeli pełny `OptimizerFlowTest.php` nadal zgłosi porażkę w teście z linii 208 dotyczącym blokady zawodnika, odnotować ją jako istniejącą, niezwiązaną regresję i nie rozszerzać zakresu tego planu.

## Validation and Acceptance

- `OptimizerFlowTest` potwierdza, że komponent zwraca tylko główną rekomendację bazową jako dane pomocnicze i nie renderuje tekstu „Alternatywy” ani „Propozycja składu”.
- Każdy poprawny wpis `rankedPlans` ma siedem kluczy boiska, pięć pozycji `bench` oraz stabilny `variant_key`; kolejność ławki jest deterministyczna, a puste miejsca są jawne.
- Test z analizą tylko części pozycji potwierdza, że pozostałe pozycje pochodzą z rekomendacji najniższych pasków treningowych i nie z bieżącej taktyki VM.
- Test z limitem rezerwowych `0` potwierdza startera bez rezerwowego i bez wygenerowanej zmiany dla tej pozycji.
- Test z brakującą bazową pozycją potwierdza widoczny komunikat i brak możliwości wywołania zapisu.
- `LineupTacticsPushTest` potwierdza, że kliknięcie wysyłki zapisuje dokładnie lineup, ławkę i reguły zaznaczonego Wariantu; osobny przypadek planu bez zmian potwierdza, że nie trafia on do rankingu.
- `VmConnectionAndSubstitutionsTest` i `VmTacticsServiceTest` pozostają zielone, co dowodzi zgodności mapowania lokalnych ID z VM.
- Manual flow:
  1. Otworzyć ekran optymalizacji, ustawić dane generujące co najmniej dwa Scenariusze lub kilka Wariantów.
  2. Wybrać kartę Wariantu w konkretnym Scenariuszu.
  3. Sprawdzić, że panel pokazuje siedmiu starterów, pięć miejsc ławki i tylko reguły wybranego Wariantu.
  4. Przełączyć Wariant i potwierdzić zmianę składu, ławki, metryk oraz reguł bez przeładowania strony.
  5. Wysłać poprawny Wariant; potwierdzić zapis pełnej taktyki i jego reguł zmian.
  6. Wywołać przypadek niepełnej bazy; potwierdzić, że Wariant pozostaje widoczny, ale przycisk wysyłki jest zablokowany z wyjaśnieniem.

## Idempotence and Recovery

- Re-run safety: computed dane Wariantów są czystą funkcją aktualnego wejścia; ponowne wejście na stronę lub przeliczenie nie dopisuje zawodników do ławki ani nie zmienia `variant_key`.
- Selection recovery: po zmianie rankingu nieistniejący `selectedVariantKey` jest zastępowany pierwszym legalnym Wariantem, a brak Wariantów pokazuje stan pusty.
- Push recovery: przygotowanie i walidacja payloadów odbywa się przed HTTP; istniejąca deduplikacja `VmSubstitutionService::pushPreparedPayloads` pozostaje używana. Nie ponawiać częściowo wykonanego zapisu ręcznie w komponencie.
- Failure recovery: błąd VM pokazuje komunikat i zachowuje wybór; ponowienie korzysta z tego samego stabilnego Wariantu i aktualnych danych komponentu.

## Rollback / Fallback

- Wycofanie kodu może przywrócić poprzedni render przez revert pliku `resources/views/pages/optimizer/⚡result.blade.php`; nie ma migracji ani zmian publicznego API.
- Jeżeli integracja VM nie działa, panel nadal może prezentować pełny Wariant i zablokować wysyłkę po błędzie, bez utraty danych optymalizacji.
- Nie przywracać niezależnej „Propozycji składu” jako cichego fallbacku, ponieważ ponownie stworzyłaby rozjazd między widokiem a Wariantem.

## Artifacts and Notes

Przykładowy kształt danych panelu:

    {
      "variant_key": "3:1|setter=101|outside_1=102|...|set1:201>301",
      "scenario_label": "Trudne 3:1",
      "is_sendable": true,
      "lineup": {
        "setter": {"id": 101, "name": "..."},
        "outside_1": {"id": 102, "name": "..."},
        "middle_1": {"id": 103, "name": "..."},
        "opposite": {"id": 104, "name": "..."},
        "outside_2": {"id": 105, "name": "..."},
        "middle_2": {"id": 106, "name": "..."},
        "libero": {"id": 107, "name": "..."}
      },
      "bench": [
        {"id": 201, "name": "..."},
        null,
        null,
        null,
        null
      ],
      "missing_slots": []
    }

`bench` jest listą pięciu slotów VM, a nie listą wszystkich możliwych kandydatów. `null` jest prawidłowym, widocznym pustym miejscem.

## Interfaces and Dependencies

- `TrainingOptimizerService::optimize(...)` w `app/TrainingOptimizerService.php` — źródło najwyżej ocenionych Wariantów per Scenariusz.
- `LineupRecommendationService::recommend(...)` w `app/LineupRecommendationService.php` — źródło bazowej pełnej siódemki.
- `VmTacticsService::COURT_SLOT_KEYS` w `app/VmTacticsService.php` — jedyna ustalona kolejność i nazwy siedmiu slotów boiska.
- `VmTacticsService::pushVariantTactics(...)` w `app/VmTacticsService.php` — zapis starterów i rezerwowych.
- `VmSubstitutionService::buildPayloads(...)` oraz `pushPreparedPayloads(...)` w `app/VmSubstitutionService.php` — walidacja, mapowanie i zapis reguł zmian.
- Livewire computed properties i akcje w `resources/views/pages/optimizer/⚡result.blade.php` — stan wyboru, render i orkiestracja kliknięcia.
- Flux UI components używane już w widoku — zachować istniejący styl i loading/error semantics; nie dodawać nowej biblioteki.

## PR Exit Checklist

- [ ] Sekcja „Propozycja składu”, „Alternatywy” i ich niezależne akcje nie są renderowane.
- [ ] Każdy Scenariusz pokazuje swoje Warianty, a panel szczegółów należy do aktualnego `selectedVariantKey`.
- [ ] Każdy legalny Wariant ma pełną siódemkę i dokładnie pięć miejsc ławki.
- [ ] Wariant niepełny albo wymagający ponad pięciu rezerwowych nie może zostać wysłany.
- [ ] Wariant bez żadnej zmiany nie jest obliczany ani prezentowany; brak zmiany w pojedynczym slocie pozostaje dozwolony.
- [ ] Przyszłe wymuszenia zawodników pozostają niewdrożone, ale model ma wejście, w które można je wprowadzić jako ograniczenie.
- [ ] Testy focused i regresyjne wykonane; znana niezwiązana porażka jest odnotowana.
- [ ] `vendor/bin/pint --dirty --format agent` przechodzi.
- [ ] `CONTEXT.md`, `Progress`, `Decision Log` i `Outcomes & Retrospective` są zaktualizowane.

Plan Change Note: 2026-09-09 — utworzono plan po zakończeniu grillowania; zapisano decyzję o usunięciu ogólnej propozycji składu, pełnej siódemce per Wariant, stałej ławce pięciu miejsc, dynamicznym panelu wyboru oraz odfiltrowaniu planów bez żadnej zmiany.
