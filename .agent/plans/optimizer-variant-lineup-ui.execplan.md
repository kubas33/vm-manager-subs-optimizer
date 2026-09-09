# Optymalizacja UI wariantów składu i ławki rezerwowych

This ExecPlan is a living document. The sections `Progress`, `Surprises & Discoveries`, `Decision Log`, and `Outcomes & Retrospective` must be kept up to date as work proceeds.

This plan must be maintained in accordance with `.agent/PLANS.md` at the repository root.

## Purpose / Big Picture

Po wdrożeniu ekran `optimizer/result` będzie odpowiadał rzeczywistemu modelowi domenowemu optymalizatora: użytkownik najpierw wybierze **Scenariusz wyniku meczu**, następnie jeden z trzech **Wariantów planu zmian** tego scenariusza, a jeden panel szczegółów pokaże dokładnie ten pełny wariant, który zostanie zapisany w VM Managerze. Zniknie myląca, niezależna sekcja „Propozycja składu”; zamiast niej aktywny wariant pokaże pełnych siedmiu starterów, wymaganą ławkę, plan zmian per set oraz efekt treningowy. Wariant bez reguł zmian pozostaje prawidłowym wariantem i może zapisać sam pełny skład. Wysłanie będzie blokowane wyłącznie wtedy, gdy pełnego wariantu nie da się legalnie zbudować lub zapisać.

Docelowa hierarchia UI jest jednoznaczna:

    Wynik optymalizacji
    └── Scenariusz wyniku meczu
        ├── Wariant #1 — Rekomendowany
        ├── Wariant #2
        └── Wariant #3
            ├── pełny skład
            ├── ławka
            ├── plan zmian
            └── efekt treningowy

`OPTIMIZER_RESULT_UI_GUIDE.md` jest repozytoryjnym guide'em UX dla tego ekranu. Ten ExecPlan jest samowystarczalną specyfikacją implementacyjną tego kierunku; jeżeli podczas implementacji pojawi się świadome odstępstwo od guide'u, musi zostać zapisane w `Decision Log` wraz z uzasadnieniem.

## Scope Freeze

In scope:

- Przebudowa `resources/views/pages/optimizer/⚡result.blade.php` do modelu **scenario-first**: osobny wybór aktywnego Scenariusza wyniku meczu i osobny wybór Wariantu #1/#2/#3 w ramach tego scenariusza.
- Usunięcie niezależnej sekcji „Propozycja składu”, jej przycisków i modala z głównej hierarchii wyniku; zachowanie wyboru typu meczu oraz globalnej akcji usuwania zmian, ale z mniejszym priorytetem wizualnym.
- Zastąpienie rozbudowanych sekcji parametrów wejściowych kompaktowym podsumowaniem z możliwością rozwinięcia pełnych danych.
- Zbudowanie dla każdego Wariantu pełnej siódemki: starterzy pozycji analizowanych przez optymalizator oraz deterministyczna rekomendacja bazowa dla pozycji nieanalizowanych.
- Wydzielenie wspólnego serwisu `VariantLineupComposer`, który będzie jedynym źródłem prawdy dla pełnego składu, ławki, VM ID i powodów blokujących wysyłkę.
- Zbudowanie ławki Wariantu jako pięciu miejsc VM (`player` albo `null`) z unikalnymi zawodnikami wynikającymi z `substitution_player`, bez starterów i bez cichego obcinania nadmiaru.
- Prezentacja wariantów #1–#3 w kompaktowej formie porównawczej wraz z głównymi metrykami rankingu oraz krótkim opisem różnic #2/#3 względem rekomendacji #1.
- Jeden dynamiczny panel szczegółów dla aktywnego Wariantu: pełna siódemka, źródło startera (`bazowy` / `optymalizowany`), ławka, metryki, efekt treningowy `player_results`, reguły zmian per set i błędy blokujące wysyłkę.
- Stabilne `scenario_key` i `variant_key`, niezależne od indeksów tablicy i tekstów wyświetlanych użytkownikowi.
- Wysyłka każdego poprawnego Wariantu, również wariantu z `substitutions_count === 0`. Pełna taktyka ma zostać zapisana niezależnie od tego, czy istnieją reguły zmian.
- Zmiana kontraktu zapisu taktyki wariantu tak, aby VM otrzymywał jawnie ten sam zestaw starterów i rezerwowych, który pokazuje UI, zamiast ponownie wyliczać ławkę z payloadów zmian.
- Jawne `send_blockers` opisujące wszystkie przyczyny, dla których wariantu nie można wysłać.
- Usunięcie z nowego result UI elementów sugerujących wspólny „worst case”/globalny najlepszy plan, jeżeli bieżąca ścieżka nadal liczy niezależne rankingi per Scenariusz wyniku meczu.
- Testy Pest pokrywające model pełnego wariantu, mapowanie slotów, wybór scenariusza i wariantu, porównanie danych, wariant bez zmian, blokady oraz wysyłkę.
- Aktualizacja `CONTEXT.md` o decyzje domenowe, które faktycznie powinny stać się trwałą częścią języka projektu, oraz utrzymywanie tego planu jako źródła decyzji implementacyjnych.

Out of scope:

- Zmiana algorytmu oceniania Wariantów, kolejności kryteriów rankingu, generowania Scenariuszy wyniku meczu lub liczby trzech Wariantów na Scenariusz.
- Implementacja wspólnego wieloscenariuszowego rankingu safety/worst-case. Jeżeli `scenarioSafetyMode` ma w przyszłości faktycznie oceniać jeden plan w wielu scenariuszach, wymaga osobnego zadania i osobnego kontraktu UX.
- Implementacja ręcznego wymuszenia konkretnego zawodnika na pozycji. Przyszłe wymuszenie ma wejść jako ograniczenie wejściowe optymalizacji i obowiązywać we wszystkich Wariantach, nie jako podmiana po obliczeniu.
- Odczyt bieżącej taktyki z VM jako źródło składu bazowego.
- Zmiana publicznych endpointów API, formatu autoryzacji albo migracji bazy danych.
- Zwiększenie ławki ponad pięć miejsc, ciche obcinanie zawodników lub przepuszczanie nielegalnych Wariantów.
- Zmiana celu `LineupRecommendationService`; serwis pozostaje źródłem bazowej siódemki dla pozycji nieanalizowanych.
- Dodawanie nowej biblioteki UI.

## API/UI Contract Snapshot

Ta zmiana nie dodaje publicznego endpointu HTTP. Livewire pozostaje właścicielem stanu strony wyniku, a istniejące usługi VM pozostają granicą zapisu.

### Główny model prezentacyjny

Nowy result UI **nie może być zbudowany wokół spłaszczonego `rankedPlans`**. Źródłem struktury jest `scenarioRankings()` lub nowa computed property zbudowana bezpośrednio na niej, np. `scenarioVariants()`.

Docelowy kształt logiczny:

    scenarioVariants[]
      scenario_key
      label
      input
      sets_count
      variants[]
        variant_key
        rank
        metrics
        plan
        player_results
        lineup
        bench
        starter_vm_player_ids
        bench_vm_player_ids
        send_blockers
        is_sendable
        differences_from_recommendation

`rankedPlans()` może pozostać tymczasowo jako helper zgodności dla istniejącego kodu/testów, ale nowy render, wybór i akcja wysyłki nie mogą zależeć od globalnie spłaszczonej listy.

### Klucze stabilne

- `scenario_key` musi być stabilny dla semantycznie tego samego Scenariusza wyniku meczu. Preferować istniejący stabilny identyfikator z wejścia; jeśli go nie ma, zbudować klucz z kanonicznie znormalizowanego `scenario_input`. Nie używać indeksu tablicy ani `scenario_label`.
- `variant_key` musi wynikać z `scenario_key` oraz kanonicznej sygnatury starterów i reguł zmian.
- Sygnatura Wariantu musi być niezależna od kolejności elementów wejściowych: sloty sortować deterministycznie według pozycji i `slot_number`, sety po numerze seta, a reguły po stałych polach identyfikujących startera, zawodnika wchodzącego i moment aktywacji.
- Do kluczy używać trwałych lokalnych ID zawodników, nie nazw wyświetlanych użytkownikowi.
- Dopuszczalne jest hashowanie kanonicznego payloadu, np. SHA-256; ważniejsza od konkretnego algorytmu jest kanoniczność i deterministyczność.

### Stan wyboru Livewire

Stan musi jawnie rozróżniać dwa poziomy wyboru:

    public string $selectedScenarioKey = '';
    public string $selectedVariantKey = '';

Zachowanie:

- domyślnie aktywny jest pierwszy Scenariusz wyniku meczu;
- domyślnie aktywny jest jego Wariant #1 — Rekomendacja zmian;
- zmiana Scenariusza automatycznie wybiera jego #1;
- zmiana Wariantu nie zmienia aktywnego Scenariusza;
- po ponownym obliczeniu, jeśli `selectedScenarioKey` zniknie, wybór wraca do pierwszego Scenariusza;
- jeśli scenariusz nadal istnieje, ale `selectedVariantKey` zniknie, wybór wraca do jego #1;
- scenariusz bez legalnych wariantów pozostaje widoczny jako stan pusty zamiast powodować wybór wariantu z innego scenariusza.

### Pełny Wariant

`VariantLineupComposer` ma zwracać co najmniej:

    lineup: {
      setter: {...},
      outside_1: {...},
      middle_1: {...},
      opposite: {...},
      outside_2: {...},
      middle_2: {...},
      libero: {...}
    }
    bench: [player|null, player|null, player|null, player|null, player|null]
    starter_vm_player_ids: [int, ... exactly 7 when sendable]
    bench_vm_player_ids: [int, ... max 5]
    send_blockers: [...]
    is_sendable: bool

Każdy wpis `lineup` powinien pozwalać UI określić źródło startera:

- `base` / `bazowy` — pozycja pochodzi z głównej rekomendacji `LineupRecommendationService`;
- `optimized` / `optymalizowany` — starter został narzucony przez aktywny Wariant planu zmian.

Composer musi być użyty zarówno przez dane renderowane użytkownikowi, jak i przez akcję wysyłki. Komponent Livewire ani `VmTacticsService` nie mogą po raz drugi odtwarzać własnej wersji ławki lub starterów.

### Mapowanie slotów analizowanych

`slot_number` optymalizatora nie jest numerem pozycji `player1..player7` w VM. Mapowanie musi korzystać z semantyki pozycji oraz kolejności slotów tej pozycji.

Przykład: jeśli optymalizowane są dwa miejsca środkowych, pierwszy slot tej pozycji mapuje się na `middle_1`, a drugi na `middle_2`. Analogicznie dla dwóch przyjmujących. Pojedyncze pozycje mapują się na swój jeden klucz boiska.

Implementacja powinna użyć istniejących definicji slotów/mappingu z optymalizatora lub obecnej poprawnej logiki, a nie tworzyć założenia `slot_number -> playerN`. Test obowiązkowo obejmuje co najmniej jedną pozycję występującą dwukrotnie.

### Ławka

- Model ławki ma dokładnie pięć miejsc VM.
- Rezerwowi wynikają z unikalnych `substitution_player` aktywnego Wariantu.
- Starter nie może pojawić się na ławce.
- Szósty wymagany rezerwowy nie może być cicho obcięty; taki Wariant jest niesendowalny.
- UI nie musi renderować pięciu dużych pustych kart. Może pokazać zajęte miejsca i tekst `3 wolne miejsca`, o ile model nadal zachowuje pięć jawnych slotów.

### `send_blockers`

`is_sendable` nie może być jedyną informacją diagnostyczną. Musi wynikać z pustej listy `send_blockers`.

Przykładowe kody:

    missing_lineup_slot
    duplicate_starter
    missing_vm_id
    invalid_vm_id
    unavailable_player
    bench_overflow
    starter_on_bench
    duplicate_vm_id

Każdy blocker powinien zawierać czytelny `message` oraz, gdy ma zastosowanie, `player_id`, `player_name` i `slot_key`.

UI musi wskazywać konkretnego zawodnika lub slot, np. `Jan Kowalski — brak ID VM`, zamiast ogólnego `Brak ID VM`.

### Porównanie wariantów

Każdy Scenariusz pokazuje wyłącznie swoje #1/#2/#3. Karty porównawcze pokazują co najmniej:

- całkowity zysk treningowy,
- liczbę zawodników poniżej progu fairness,
- najniższy końcowy pasek / informację o profilu fairness,
- zmarnowane akcje,
- liczbę zmian.

Priorytet wizualny odpowiada rzeczywistej kolejności rankingu `TrainingOptimizerService`: najpierw zysk treningowy, potem fairness, potem waste i liczba zmian.

Wariant #1 ma badge `Rekomendowany`. Dla #2 i #3 wyliczyć `differences_from_recommendation`, np.:

    2 innych starterów · 1 inna reguła zmiany

lub szczegółowo:

    R: Kowalski -> Nowak
    Ś2: Wiśniewski -> Zieliński

Porównanie korzysta z już skomponowanego lineupu i kanonicznych reguł zmian, nie z tekstowych opisów Blade.

### Panel szczegółów

Jeden panel aktywnego Wariantu pokazuje:

1. nazwę aktywnego Scenariusza i rangę Wariantu,
2. pełną siódemkę w układzie boiska,
3. badge `bazowy` / `optymalizowany` przy starterach,
4. ławkę Wariantu,
5. metryki,
6. plan zmian per set,
7. efekt treningowy z `player_results`,
8. `send_blockers`, jeśli istnieją,
9. główne CTA zastosowania Wariantu.

Stary panel `Szczegóły najlepszego wariantu` oparty o `$this->rankedPlans[0]` musi zniknąć. `player_results` ma zawsze należeć do aktywnego Scenariusza i aktywnego Wariantu.

### Parametry wejściowe

Nie renderować na początku strony kilku dużych sekcji `Pozycje`, `Tryb wejścia`, `Scenariusze`, `Pule rezerwowych`, `Znormalizowane scenariusze`.

Zastąpić je kompaktowym summary, np.:

    Rozgrywający + 2x Środkowy · próg 20% · max 5 rezerwowych · Standardowy
    [Pokaż parametry wejściowe]

Pełne dane można zachować w collapsible/details/accordionie. Właściwe scenariusze i warianty powinny być widoczne wysoko na stronie.

### `scenarioSafetyMode`

Bieżący result flow liczy ranking niezależnie dla każdego Scenariusza wyniku meczu przez `optimize()`. W ramach tego planu UI ma odzwierciedlać właśnie ten model.

Jeżeli w starym renderze istnieją elementy `worst scenario`, `safe mode` lub inne fragmenty sugerujące wspólną ocenę jednego planu w kilku scenariuszach, należy je usunąć lub ukryć z nowego result UI, o ile nie są poparte faktycznie wykonywaną ścieżką obliczeń. Nie zmieniać algorytmu w tym zadaniu.

### Zapis wariantu do VM

Akcja powinna semantycznie dotyczyć całego Wariantu, np. `applyVariant()` / `requestApplyVariant()` / `confirmApplyVariant()`, a nie tylko zmian.

Przebieg:

1. pobrać aktywny Scenariusz i aktywny Wariant po stabilnych kluczach;
2. pobrać wynik `VariantLineupComposer`;
3. przerwać przed HTTP, jeśli istnieją `send_blockers`;
4. przygotować payloady zmian przez `VmSubstitutionService::buildPayloads(...)`;
5. zapisać pełną taktykę z **jawnie przekazanymi** `starter_vm_player_ids` i `bench_vm_player_ids` z composera;
6. jeżeli payloady zmian istnieją, przekazać je do `VmSubstitutionService::pushPreparedPayloads(...)`;
7. jeżeli payloadów nie ma, uznać operację za sukces po zapisaniu taktyki.

Obecny `VmTacticsService::pushVariantTactics(...)` wylicza ławkę ponownie z `playerIn` w payloadach zmian. W ramach tego zadania zmienić jego kontrakt lub wprowadzić równoważną metodę tak, aby przyjmowała jawnie starterów i rezerwowych skomponowanych przez `VariantLineupComposer`. Docelowa odpowiedzialność powinna odpowiadać np.:

    pushVariantTactics(
        array $starterVmPlayerIds,
        array $benchVmPlayerIds,
        string $matchType = 'League',
        int $matchId = 0,
    )

Jeżeli zmiana sygnatury wpływa na inne istniejące call-site'y, zaktualizować je w tym samym milestone i zachować testy regresyjne. Nie utrzymywać drugiej ścieżki, która samodzielnie rekonstruuje ławkę z `substitutionPayloads`.

### Modal potwierdzenia

Przed zapisem do VM pokazać modal zawierający co najmniej:

- typ meczu,
- Scenariusz wyniku meczu,
- numer Wariantu,
- siedmiu starterów,
- liczbę zajętych miejsc ławki,
- liczbę reguł zmian.

CTA ma jasno oznaczać zapis całego Wariantu, np. `Wyślij ten wariant do VM Managera`.

## Progress

- [x] (2026-09-09) Przeanalizować komponent wyniku, optymalizator oraz integrację zapisu VM.
- [x] (2026-09-09) Uzgodnić podstawową terminologię Scenariusz wyniku meczu / Wariant planu zmian / Rekomendacja zmian.
- [x] (2026-09-09) Porównać pierwotny ExecPlan z `OPTIMIZER_RESULT_UI_GUIDE.md` i skorygować kontrakt do modelu scenario-first.
- [ ] Zweryfikować bieżące wystąpienia `scenarioSafetyMode`/worst-case i usunąć z nowego UI semantykę niepopartą faktyczną ścieżką obliczeń.
- [ ] Utworzyć `VariantLineupComposer` i testy jego mapowania, pełnego składu, ławki oraz `send_blockers`.
- [ ] Przygotować scenariuszowy view-model, stabilne `scenario_key`/`variant_key` i dwupoziomowy stan wyboru.
- [ ] Przebudować result UI: compact input summary, scenario tabs, porównanie #1/#2/#3 i jeden panel szczegółów z `player_results`.
- [ ] Przebudować akcję zastosowania Wariantu i zapis VM tak, aby używały jawnie skomponowanych starterów i ławki oraz obsługiwały `substitutions_count === 0`.
- [ ] Zaktualizować `CONTEXT.md` o trwałe decyzje domenowe dotyczące pełnego wariantu/ławki, jeśli po implementacji nadal są właściwe dla domeny, a nie wyłącznie dla prezentacji.
- [ ] Dodać/zmienić testy i przeprowadzić walidację focused oraz regresyjną.
- [ ] Uzupełnić ten plan o wynik wdrożenia i retrospektywę.

## Surprises & Discoveries

- `TrainingOptimizerService` generuje Warianty tylko dla pozycji objętych analizą; plan nie zawiera sam z siebie pełnej siódemki. `scenarioRankings()` przekazuje `slotDefinitions`, a każdy `plan['slots']` reprezentuje wyłącznie analizowane sloty.
- Obecny panel `Szczegóły najlepszego wariantu` bierze `$this->rankedPlans[0]`, więc tworzy pojęcie globalnego najlepszego planu, którego nie ma w modelu domenowym niezależnych rankingów per scenariusz.
- Istniejący `starterVmPlayerIdsForPlan()` nakłada starterów Wariantu na ukrytą główną rekomendację. To poprawna idea, ale obecna implementacja jest ukryta w komponencie i powinna zostać wydzielona do wspólnego composera.
- `resources/views/pages/optimizer/⚡create.blade.php` odrzuca sumę limitów rezerwowych większą niż pięć, a limit `0` nadal pozwala na startera i wyłącza kandydatów zmian.
- Aktualny helper `variantBenchPlayers()` zwraca tylko zawodników występujących jako `substitution_player`; model docelowy musi zachowywać pięć miejsc VM, ale UI może wizualnie agregować puste miejsca.
- `VmTacticsService::pushVariantTactics()` obecnie ponownie wylicza bench z `playerIn` payloadów zmian. To narusza zasadę `what you see is what gets pushed`, ponieważ UI i zapis nie korzystają z dokładnie tego samego modelu ławki.
- Wersja ExecPlanu znajdująca się na `main` przed tą korektą odfiltrowywała Warianty z `substitutions_count === 0`. Jest to sprzeczne z możliwością poprawnego zapisania samej pełnej taktyki i zostało wycofane w tej rewizji planu.
- Aktualny `CONTEXT.md` definiuje podstawową terminologię i relacje scenariuszy/wariantów, ale nie zawiera jeszcze pełnego kontraktu siedmiu starterów i pięciu miejsc ławki. Nie oznaczać tej części dokumentacji jako ukończonej, dopóki faktycznie nie zostanie zaktualizowana.
- Skupione testy dla wyniku i zapisu wcześniej przechodziły. Pełny `OptimizerFlowTest.php` ma istniejącą, niezwiązaną porażkę testu blokady zawodnika w nieoptymalizowanym slocie (`tests/Feature/OptimizerFlowTest.php:208`); nie należy naprawiać jej przypadkiem w ramach tego UI bez potwierdzenia, że nadal jest niezależna.

## Decision Log

- Decision: UI jest scenario-first: użytkownik najpierw wybiera Scenariusz wyniku meczu, a dopiero potem jeden z jego #1/#2/#3. Rationale: każdy scenariusz ma osobny ranking i własną Rekomendację zmian; spłaszczona globalna lista zaciera tę relację. Date/Author: 2026-09-09 / użytkownik i agent.
- Decision: Nowy render nie opiera się na spłaszczonym `rankedPlans`. Rationale: `scenarioRankings` naturalnie odpowiada domenie i zapobiega tworzeniu globalnego `bestPlan`. Date/Author: 2026-09-09 / użytkownik i agent.
- Decision: Usunąć całą niezależną sekcję `Propozycja składu` z głównego ekranu wyniku. Rationale: nie jest własnością żadnego Scenariusza i może pokazywać innych starterów niż aktywny Wariant. Date/Author: 2026-09-09 / użytkownik i agent.
- Decision: Każdy Wariant pokazuje pełną siódemkę. Rationale: użytkownik musi widzieć dokładnie to, co zostanie zapisane; pozycje nieanalizowane dostają rekomendację bazową. Date/Author: 2026-09-09 / użytkownik i agent.
- Decision: Skład bazowy pochodzi z głównej rekomendacji `LineupRecommendationService`, nie z bieżącej taktyki VM. Rationale: zachowuje obecny cel optymalizacji i nie wprowadza dodatkowego ukrytego źródła danych. Date/Author: 2026-09-09 / użytkownik i agent.
- Decision: Logika składania pełnej siódemki i ławki trafia do `VariantLineupComposer`, a nie do rosnącego `⚡result.blade.php`. Rationale: UI i zapis VM muszą korzystać z jednego modelu i tej samej walidacji. Date/Author: 2026-09-09 / agent po review planu.
- Decision: `VmTacticsService` otrzymuje jawnie starterów i rezerwowych z composera zamiast odtwarzać bench z payloadów zmian. Rationale: gwarantuje zgodność renderu z payloadem VM. Date/Author: 2026-09-09 / agent po review planu.
- Decision: Wariant z `substitutions_count === 0` pozostaje legalnym i prezentowanym Wariantem. Rationale: może reprezentować optymalny skład startowy bez potrzeby reguł zmian; pełna taktyka nadal musi dać się zapisać. Date/Author: 2026-09-09 / użytkownik i agent.
- Decision: Ławka ma pięć miejsc w modelu VM; Wariant wymagający więcej jest nielegalny, ale UI nie musi eksponować pięciu dużych pustych kart. Rationale: model odpowiada ograniczeniu VM bez niepotrzebnego szumu wizualnego. Date/Author: 2026-09-09 / użytkownik i agent.
- Decision: `is_sendable` wynika z pustego `send_blockers`, a blockery są jawne i diagnostyczne. Rationale: użytkownik musi wiedzieć dokładnie, co blokuje zapis. Date/Author: 2026-09-09 / agent po review planu.
- Decision: #2 i #3 pokazują różnice względem #1. Rationale: same metryki nie wyjaśniają szybko, czym praktycznie różnią się warianty. Date/Author: 2026-09-09 / użytkownik i agent.
- Decision: Stary globalny panel `Szczegóły najlepszego wariantu` zostaje zastąpiony efektem treningowym aktywnego Wariantu. Rationale: `rankedPlans[0]` nie reprezentuje globalnego optimum domenowego. Date/Author: 2026-09-09 / użytkownik i agent.
- Decision: Rozbudowane parametry wejściowe są zwiniętym kontekstem, nie główną treścią result page. Rationale: użytkownik dopiero co je podał i na ekranie wyniku oczekuje przede wszystkim decyzji scenariusz/wariant. Date/Author: 2026-09-09 / użytkownik i agent.
- Decision: Bieżący result UI odzwierciedla niezależne rankingi per Scenariusz; nie pokazuje safety/worst-case bez faktycznego wieloscenariuszowego rankingu. Rationale: UI nie może sugerować działania algorytmu, którego aktualna ścieżka nie wykonuje. Date/Author: 2026-09-09 / agent po review planu.
- Decision: Limit rezerwowych `0` pozostawia startera, ale nie generuje rezerwowego ani zmiany dla pozycji. Rationale: limit dotyczy ławki, nie obsady na boisku. Date/Author: 2026-09-09 / użytkownik i agent.
- Decision: Przyszłe ręczne wskazanie zawodnika będzie ograniczeniem przed optymalizacją. Rationale: musi wpływać spójnie na wszystkie Warianty, a nie być naprawą jednego wyniku po fakcie. Date/Author: 2026-09-09 / użytkownik i agent.

## Outcomes & Retrospective

Plan został ponownie uzgodniony z docelowym guide'em UX. Najważniejsza korekta polega na przejściu z „płaskiej listy pogrupowanej scenariuszami” do rzeczywistego modelu scenario-first oraz na rozdzieleniu logiki składania pełnego Wariantu od komponentu Blade. Implementacja nie jest jeszcze wykonana. Po zakończeniu należy odnotować, czy `VariantLineupComposer` faktycznie zapewnia identyczny lineup/bench w renderze i payloadzie VM, czy przełączanie scenariuszy jest intuicyjne oraz czy użytkownik potrafi szybko odróżnić #2/#3 od rekomendacji #1.

## Context and Orientation

Repozytorium jest aplikacją Laravel z komponentem Livewire/Volt dla optymalizacji zmian. Kod bieżącego widoku wyniku i stan Livewire znajdują się w jednym dużym pliku SFC; usługi domenowe i integracja VM są zwykłymi klasami PHP.

- `OPTIMIZER_RESULT_UI_GUIDE.md`: repozytoryjny guide UX dla docelowego `optimizer/result`; zawiera scenario-first, porównanie wariantów, pełny skład, compact input summary i zasady wysyłki.
- `resources/views/pages/optimizer/⚡result.blade.php`: komponent wyniku; zawiera `scenarioRankings()`, `rankedPlans()`, składanie starterów, wysyłkę i obecne sekcje UI.
- `resources/views/pages/optimizer/⚡create.blade.php`: formularz wejściowy; waliduje limity rezerwowych, w tym sumę maksymalnie pięciu.
- `app/TrainingOptimizerService.php`: oblicza rankingi Wariantów dla pojedynczego Scenariusza wyniku meczu.
- `app/LineupRecommendationService.php`: wybiera bazową rekomendację pełnej siódemki z dostępnych zawodników według pasków treningowych.
- `app/VmTacticsService.php`: definiuje siedem kluczy boiska (`COURT_SLOT_KEYS`) i zapisuje starterów oraz rezerwowych do VM; obecne `pushVariantTactics()` odtwarza bench z payloadów zmian i wymaga zmiany kontraktu.
- `app/VmSubstitutionService.php`: tłumaczy lokalne `starter_player`/`substitution_player` na payloady zmian i wysyła je z deduplikacją.
- `tests/Feature/OptimizerFlowTest.php`: testy renderu, rankingu i danych komponentu.
- `tests/Feature/LineupTacticsPushTest.php`: testy wysyłki pełnej taktyki.
- `tests/Feature/VmConnectionAndSubstitutionsTest.php` oraz `tests/Unit/VmTacticsServiceTest.php`: testy mapowania i kontraktu VM.
- `CONTEXT.md`: obecnie definiuje podstawową terminologię Scenariusz wyniku meczu / Wariant planu zmian / Rekomendacja zmian oraz relacje między scenariuszami; nie zawiera jeszcze pełnego kontraktu siedmiu starterów i pięciu miejsc ławki.

Definicje używane w tym planie:

- **Scenariusz wyniku meczu** — konkretny rozpatrywany wynik/przebieg meczu, np. standardowe 3:0, 3:1 albo 3:2. Każdy scenariusz jest oceniany niezależnie.
- **Wariant planu zmian** — jedna dopuszczalna propozycja starterów i ewentualnych zmian w ramach jednego Scenariusza wyniku meczu.
- **Rekomendacja zmian** — Wariant #1 danego Scenariusza; nie istnieje jedna wspólna rekomendacja dla wszystkich scenariuszy.
- **Pełny Wariant** — Wariant planu zmian rozszerzony o starterów pozycji nieanalizowanych, pięć miejsc ławki VM, VM ID, dane diagnostyczne i efekt treningowy.
- **Ławka** — pięć miejsc w 12-osobowym składzie VM; nie dowolnie długa lista kandydatów.
- **Composer** — serwis składający jeden jawny Pełny Wariant z bazowej rekomendacji oraz planu optymalizatora.

## Plan of Work

### Milestone 1 — `VariantLineupComposer` i scenariuszowy model danych

Utworzyć `app/VariantLineupComposer.php` zgodnie z konwencjami projektu. Serwis przyjmuje bazową główną rekomendację oraz surowy `plan` Wariantu i zwraca jeden jawny model pełnego składu. Nie implementować tej logiki jako kolejnego dużego helpera wyłącznie w `resources/views/pages/optimizer/⚡result.blade.php`.

Composer najpierw mapuje bazową rekomendację po `VmTacticsService::COURT_SLOT_KEYS`, potem nakłada starterów analizowanych slotów. Mapowanie wielokrotnych pozycji musi być semantyczne: np. dwa sloty środkowych kolejno trafiają do `middle_1` i `middle_2`; dwa sloty przyjmujących do `outside_1` i `outside_2`. Zbudować bench z unikalnych `substitution_player`, wykluczyć starterów, zachować pięć miejsc oraz przygotować jawne `starter_vm_player_ids` i `bench_vm_player_ids`.

Zamiast `missing_slots + bool` stworzyć `send_blockers`. Walidacje obejmują co najmniej brakujący slot, duplikaty starterów, brak/nieprawidłowe VM ID, niedostępnego zawodnika, startera na ławce, duplikat VM ID i przekroczenie pięciu rezerwowych. `is_sendable` jest true tylko przy pustym `send_blockers`.

Dodać jednostkowe testy composera. Krytyczny test ma obejmować optymalizację dwóch slotów tej samej pozycji, aby udowodnić, że `slot_number` nie jest błędnie traktowany jako numer `playerN` w VM.

Następnie zbudować scenariuszowy view-model na bazie `scenarioRankings()`. Każdy scenariusz otrzymuje stabilny `scenario_key`, a każdy plan `variant_key`. Nie filtrować Wariantów tylko dlatego, że `substitutions_count === 0`.

Na końcu milestone istnieje czysty, testowalny model:

    Scenariusz -> Warianty #1/#2/#3 -> Pełny Wariant

bez zależności UI od globalnie spłaszczonego `rankedPlans`.

### Milestone 2 — Scenario-first UI i porównanie Wariantów

W `resources/views/pages/optimizer/⚡result.blade.php` usunąć cały główny render `Propozycja składu`, w tym jego niezależne przyciski i modal. Bazowa rekomendacja nadal istnieje wewnętrznie jako wejście do composera, ale nie jest równoległym „głównym wynikiem”.

Na górze pozostawić kompaktowy header z typem meczu, `Zmień parametry` i drugorzędną akcją `Usuń wszystkie zmiany`. Obecne rozbudowane sekcje parametrów wejściowych zastąpić krótkim summary i rozwijaną sekcją pełnych danych.

Dodać jawny selector Scenariuszy, najlepiej tabs/segmented control zgodny z używanym Flux UI. Po wybraniu Scenariusza renderować tylko jego #1/#2/#3. Zmiana Scenariusza wybiera #1 tego scenariusza.

Karty #1/#2/#3 mają być kompaktowe i porównawcze. Pokazać metryki rankingu oraz `differences_from_recommendation` dla #2/#3. Nie renderować trzech kompletnych składów równocześnie.

Jeden panel aktywnego Wariantu renderuje:

1. aktywny Scenariusz i rangę,
2. pełną siódemkę w układzie boiska,
3. badge źródła startera `bazowy` / `optymalizowany`,
4. zajęte miejsca ławki oraz informację o wolnych miejscach,
5. metryki,
6. plan zmian per set,
7. tabelę efektu treningowego z `player_results`,
8. `send_blockers` z konkretnymi zawodnikami/slotami,
9. CTA zastosowania Wariantu.

Usunąć `Szczegóły najlepszego wariantu` oparte o `$this->rankedPlans[0]`. Nie renderować elementów safety/worst-case, które nie wynikają z faktycznie wykonywanego wieloscenariuszowego rankingu.

Na końcu milestone użytkownik może przełączyć 3:0 -> 3:1 -> 3:2, w każdym zobaczyć jego własne #1/#2/#3, a następnie przełączyć wariant i obserwować zmianę pełnego składu, ławki, planu i efektu treningowego bez przeładowania strony.

### Milestone 3 — Zastosowanie całego Wariantu do VM

Przebudować akcję wysyłki tak, aby semantycznie dotyczyła całego Wariantu, nie wyłącznie reguł zmian. Wszystkie komunikaty, loading state i modal korzystają z `selectedScenarioKey` + `selectedVariantKey`.

Przed HTTP pobrać dokładnie ten Pełny Wariant, który jest renderowany. Nie odtwarzać starterów ani bench lokalnie w akcji. Jeżeli `send_blockers` nie jest puste, nie wykonywać żadnego requestu i pokazać czytelne powody.

Zmienić `VmTacticsService::pushVariantTactics(...)` lub wprowadzić równoważną metodę tak, aby otrzymywała `starterVmPlayerIds` i `benchVmPlayerIds` bezpośrednio z composera. Usunąć z tej ścieżki ponowne wyliczanie ławki z `playerIn` w payloadach zmian.

Z planu nadal przygotować payloady reguł przez `VmSubstitutionService::buildPayloads(...)`. Najpierw zapisać pełną taktykę. Następnie:

- jeśli istnieją payloady zmian — wykonać `pushPreparedPayloads(...)`;
- jeśli lista jest pusta — zakończyć sukcesem po zapisaniu taktyki.

Wariant z `substitutions_count === 0` jest prawidłowym testowym i produkcyjnym przypadkiem. Nie tworzyć sztucznej reguły tylko po to, aby spełnić stary kontrakt.

Dodać modal potwierdzenia całej operacji: typ meczu, scenariusz, numer wariantu, siedmiu starterów, liczba rezerwowych i liczba reguł zmian.

Po błędzie VM aktywny Scenariusz i Wariant pozostają zaznaczone, aby możliwe było ponowienie.

### Milestone 4 — Dokumentacja, testy i zamknięcie

Zaktualizować `CONTEXT.md` tylko o te decyzje, które są trwałym elementem domeny, np. że wynik jednego Scenariusza ma własne Warianty i że wybrany Wariant jest stosowany jako kompletna decyzja meczowa. Nie wpisywać do `CONTEXT.md` szczegółów czysto wizualnych typu forma taba lub sposób renderowania pustych miejsc bench.

Zaktualizować testy Pest pod nowy kontrakt. Minimalnie pokryć:

- scenario-first: każdy scenariusz ma własne #1/#2/#3 i domyślnie wybiera #1;
- zmiana scenariusza nie pozostawia Wariantu z poprzedniego scenariusza;
- `VariantLineupComposer` zastępuje tylko analizowane pozycje;
- nieanalizowane pozycje pochodzą z bazowej rekomendacji;
- poprawne mapowanie dwóch slotów tej samej pozycji;
- pełny lineup renderowany w UI odpowiada dokładnie starterom wysyłanym do VM;
- bench renderowany w UI odpowiada dokładnie bench wysyłanemu do VM;
- pięć miejsc ławki i `bench_overflow` bez truncation;
- `send_blockers` dla brakującej pozycji i konkretnego zawodnika bez `vm_player_id`;
- limit rezerwowych `0` zachowuje startera bez rezerwowego i bez zmiany;
- Wariant z `substitutions_count === 0` pozostaje w rankingu i zapisuje pełną taktykę bez POST-ów zmian;
- przełączenie #1 -> #2 aktualizuje lineup, bench, metryki, `player_results` i reguły;
- #2/#3 mają poprawne różnice względem #1;
- stary tekst `Propozycja składu` i globalne `Szczegóły najlepszego wariantu` nie są renderowane;
- niezależny result flow nie pokazuje mylącej wieloscenariuszowej semantyki safety/worst-case;
- istniejące testy integracji VM pozostają zielone.

Uruchomić Pint, focused testy po milestone'ach, a następnie pełny zestaw regresyjny wskazany w `Concrete Steps`.

## Parallel Work Map

Parallel-safe tracks:

- Po ustaleniu publicznego kontraktu `VariantLineupComposer` można równolegle przygotować `tests/Unit/VariantLineupComposerTest.php` i fixture'y do testów Livewire, pod warunkiem że tylko jeden worker edytuje sam composer.
- Po ustaleniu scenariuszowego view-modelu można przygotować asercje renderu w `tests/Feature/OptimizerFlowTest.php` równolegle z testami kontraktu VM w `tests/Feature/LineupTacticsPushTest.php`, jeśli workerzy nie edytują tych samych plików produkcyjnych.
- Po stabilizacji renderu można niezależnie uruchamiać testy VM i rankingu.

Blocking dependencies:

- Milestone 2 czeka na stabilny kształt `VariantLineupComposer`, `scenario_key`, `variant_key`, `send_blockers` i scenariuszowego view-modelu z Milestone 1.
- Milestone 3 czeka na jeden wspólny model `starter_vm_player_ids` i `bench_vm_player_ids`; wysyłka nie może odtwarzać tych danych osobno.
- Testy wysyłki czekają na finalną nazwę akcji zastosowania Wariantu i kontrakt `VmTacticsService`.
- Nie uruchamiać równoległych workerów zapisujących `resources/views/pages/optimizer/⚡result.blade.php`.
- Nie pozwalać workerom UI i VM równolegle zmieniać kontraktu `VariantLineupComposer` bez wcześniejszego zamrożenia jego shape'u.

## Concrete Steps

Wykonywać z katalogu repozytorium `/home/kuba/projects/vm-manager-subs-optimizer`.

    cd /home/kuba/projects/vm-manager-subs-optimizer
    sed -n '1,320p' OPTIMIZER_RESULT_UI_GUIDE.md
    sed -n '1,260p' CONTEXT.md
    rg -n "lineupRecommendations|rankedPlans|scenarioRankings|scenarioSafetyMode|pushVariantTactics|starterVmPlayerIdsForPlan|variantBenchPlayers" resources/views/pages/optimizer/⚡result.blade.php app tests
    sed -n '1,280p' app/VmTacticsService.php
    sed -n '1,320p' app/VmSubstitutionService.php

Przed zmianą kontraktu zapisu:

    rg -n "pushVariantTactics\(" app resources tests

Po Milestone 1:

    vendor/bin/pint --dirty --format agent
    vendor/bin/sail artisan test --compact tests/Unit/VariantLineupComposerTest.php tests/Feature/OptimizerFlowTest.php

Po Milestone 2:

    vendor/bin/pint --dirty --format agent
    vendor/bin/sail artisan test --compact tests/Feature/OptimizerFlowTest.php

Po Milestone 3:

    vendor/bin/pint --dirty --format agent
    vendor/bin/sail artisan test --compact tests/Feature/LineupTacticsPushTest.php tests/Feature/VmConnectionAndSubstitutionsTest.php tests/Unit/VmTacticsServiceTest.php

Po ukończeniu implementacji:

    vendor/bin/sail artisan test --compact tests/Unit/VariantLineupComposerTest.php tests/Feature/OptimizerFlowTest.php tests/Feature/LineupTacticsPushTest.php tests/Feature/VmConnectionAndSubstitutionsTest.php tests/Unit/VmTacticsServiceTest.php
    vendor/bin/pint --dirty --format agent

Jeżeli pełny `OptimizerFlowTest.php` nadal zgłosi wcześniej znaną porażkę dotyczącą blokady zawodnika w nieoptymalizowanym slocie, najpierw potwierdzić, że failure istnieje także bez zmian tego planu. Dopiero wtedy odnotować ją jako unrelated baseline failure; nie maskować nowej regresji przez automatyczne uznanie każdego failure za istniejący.

## Validation and Acceptance

- Pierwszym poziomem nawigacji wyniku są Scenariusze wyniku meczu, a nie jedna płaska lista wszystkich Wariantów.
- Po wejściu na standardowy wynik użytkownik widzi np. tabs `3:0`, `3:1`, `3:2`; w każdym z nich wyłącznie jego #1/#2/#3.
- Zmiana Scenariusza automatycznie wybiera jego #1 i nie pokazuje danych Wariantu z poprzedniego scenariusza.
- #1 jest oznaczony `Rekomendowany` wyłącznie w ramach swojego Scenariusza.
- #2/#3 pokazują krótką różnicę względem #1.
- Nie istnieje globalny `najlepszy wariant` ani panel oparty o `$this->rankedPlans[0]`.
- Właściwe wyniki są widoczne bez przewijania przez kilka dużych sekcji parametrów wejściowych; pełne parametry pozostają dostępne po rozwinięciu.
- Aktywny Wariant pokazuje dokładnie siedmiu starterów, a każdy starter ma informację, czy pochodzi z bazy, czy z optymalizatora.
- Model bench ma dokładnie pięć miejsc; UI może pokazać zajętych rezerwowych i liczbę wolnych miejsc.
- `player_results` zmienia się razem z aktywnym Wariantem.
- `send_blockers` wskazują konkretnego zawodnika/slot i blokują request przed HTTP.
- Wariant z `substitutions_count === 0` jest widoczny, można go wybrać i może zapisać pełną taktykę bez tworzenia reguł zmian.
- Payload taktyki zawiera dokładnie starterów i bench z tego samego Pełnego Wariantu, który użytkownik widział przed potwierdzeniem.
- Żadna ścieżka `pushVariantTactics` nie rekonstruuje bench niezależnie z `playerIn`, jeżeli UI korzysta już z composera.
- `VmConnectionAndSubstitutionsTest` i `VmTacticsServiceTest` pozostają zielone.

Manual flow:

1. Otworzyć konfigurację i ustawić dane generujące co najmniej dwa Scenariusze oraz kilka Wariantów.
2. Przejść do wyniku i potwierdzić, że parametry wejściowe są skompresowane, a pierwszy Scenariusz jest aktywny.
3. Sprawdzić #1/#2/#3 i różnice #2/#3 względem #1.
4. Przełączyć Scenariusz; potwierdzić automatyczny wybór jego #1.
5. Przełączyć Wariant; potwierdzić zmianę pełnej siódemki, badge źródła, ławki, metryk, `player_results` i reguł bez przeładowania.
6. Otworzyć modal zastosowania; porównać jego siedmiu starterów i bench z panelem szczegółów.
7. Wysłać poprawny Wariant ze zmianami i potwierdzić zapis taktyki oraz reguł.
8. Wysłać poprawny Wariant bez zmian i potwierdzić zapis taktyki bez utworzonych reguł.
9. Wywołać przypadek zawodnika bez `vm_player_id`; potwierdzić, że jego nazwa jest widoczna w blockerze i żaden request nie został wykonany.
10. Wywołać przypadek niepełnej bazy lub bench >5; potwierdzić, że Wariant pozostaje widoczny diagnostycznie, ale CTA jest zablokowane z konkretną przyczyną.

## Idempotence and Recovery

- Re-run safety: `VariantLineupComposer`, `scenario_key`, `variant_key` i dane porównawcze są czystą funkcją aktualnego wejścia; ponowne wejście na stronę lub przeliczenie nie dopisuje zawodników do bench i nie zmienia kluczy dla semantycznie identycznego wejścia.
- Selection recovery: po zmianie rankingu nieistniejący `selectedScenarioKey` wraca do pierwszego Scenariusza; nieistniejący `selectedVariantKey` wraca do #1 aktualnego Scenariusza.
- Empty scenario recovery: scenariusz z zerem legalnych Wariantów pokazuje lokalny empty state; nie wybiera Wariantu z sąsiedniego scenariusza.
- Push preparation: kompletna walidacja i `send_blockers` powstają przed HTTP.
- Push recovery: istniejąca deduplikacja `VmSubstitutionService::pushPreparedPayloads` pozostaje używana. Nie ponawiać ręcznie tylko drugiej połowy częściowo zakończonego zapisu w komponencie.
- Failure recovery: błąd VM pokazuje komunikat i zachowuje aktywny Scenariusz/Wariant; ponowienie korzysta z aktualnie skomponowanych danych.

## Rollback / Fallback

- Nie ma migracji bazy ani zmian publicznego API, więc pełny rollback tego zadania powinien odbywać się przez `git revert` commitów wykonujących ten ExecPlan, nie przez przywrócenie wyłącznie jednego pliku Blade.
- Revert musi obejmować wspólnie: result component, `VariantLineupComposer`, kontrakt `VmTacticsService`, testy i ewentualną aktualizację `CONTEXT.md`.
- Jeżeli integracja VM chwilowo nie działa, UI może nadal prezentować Pełny Wariant i pokazać błąd zapisu, ale nie wolno wracać do niezależnej `Propozycji składu` jako cichego fallbacku.
- Nie utrzymywać równolegle starej i nowej ścieżki składania bench po zakończeniu migracji; fallback ma być wersjonowanym revert, a nie dwa konkurencyjne źródła prawdy w produkcji.

## Artifacts and Notes

Przykładowy kształt scenariuszowego view-modelu:

    {
      "scenario_key": "standard-3-1:<stable-hash>",
      "scenario_label": "Standardowe 3:1",
      "sets_count": 4,
      "variants": [
        {
          "variant_key": "<stable-hash>",
          "rank": 1,
          "is_recommended": true,
          "metrics": {
            "total_gained_training": 84,
            "players_below_fairness_threshold": 0,
            "wasted_actions": 0,
            "substitutions_count": 4
          },
          "lineup": {
            "setter": {"player": {"id": 101, "name": "..."}, "source": "optimized"},
            "outside_1": {"player": {"id": 102, "name": "..."}, "source": "base"},
            "middle_1": {"player": {"id": 103, "name": "..."}, "source": "optimized"},
            "opposite": {"player": {"id": 104, "name": "..."}, "source": "base"},
            "outside_2": {"player": {"id": 105, "name": "..."}, "source": "base"},
            "middle_2": {"player": {"id": 106, "name": "..."}, "source": "optimized"},
            "libero": {"player": {"id": 107, "name": "..."}, "source": "base"}
          },
          "bench": [
            {"id": 201, "name": "..."},
            null,
            null,
            null,
            null
          ],
          "starter_vm_player_ids": [101, 102, 103, 104, 105, 106, 107],
          "bench_vm_player_ids": [201],
          "send_blockers": [],
          "is_sendable": true,
          "differences_from_recommendation": [],
          "player_results": []
        }
      ]
    }

Przykład niesendowalnego Wariantu:

    {
      "is_sendable": false,
      "send_blockers": [
        {
          "code": "missing_vm_id",
          "player_id": 42,
          "player_name": "Jan Kowalski",
          "slot_key": "middle_2",
          "message": "Jan Kowalski nie ma ID VM."
        }
      ]
    }

`bench` jest listą pięciu slotów VM. `bench_vm_player_ids` zawiera tylko zajęte miejsca i jest przekazywane bezpośrednio do zapisu taktyki.

## Interfaces and Dependencies

- `TrainingOptimizerService::optimize(...)` w `app/TrainingOptimizerService.php` — źródło najwyżej ocenionych Wariantów per Scenariusz; ranking nie jest zmieniany w tym zadaniu.
- `LineupRecommendationService::recommend(...)` w `app/LineupRecommendationService.php` — źródło bazowej pełnej siódemki.
- `VariantLineupComposer` w `app/VariantLineupComposer.php` — nowy współdzielony model pełnego składu, bench, VM ID i blockerów.
- `VmTacticsService::COURT_SLOT_KEYS` w `app/VmTacticsService.php` — ustalona kolejność i nazwy siedmiu slotów boiska.
- `VmTacticsService::pushVariantTactics(...)` lub równoważna nowa metoda w `app/VmTacticsService.php` — zapis jawnie przekazanych starterów i bench; nie wylicza bench z payloadów zmian.
- `VmSubstitutionService::buildPayloads(...)` oraz `pushPreparedPayloads(...)` w `app/VmSubstitutionService.php` — walidacja, mapowanie i zapis reguł zmian.
- Livewire computed properties i akcje w `resources/views/pages/optimizer/⚡result.blade.php` — stan `selectedScenarioKey`, `selectedVariantKey`, render i orkiestracja kliknięcia.
- Flux UI components używane już w widoku — zachować istniejący styl, responsive behavior i loading/error semantics; nie dodawać nowej biblioteki.
- `OPTIMIZER_RESULT_UI_GUIDE.md` — guide UX, którego założenia zostały włączone bezpośrednio do tego ExecPlanu.

## PR Exit Checklist

- [ ] Pierwszym poziomem wyboru są Scenariusze wyniku meczu; aktywny Scenariusz pokazuje wyłącznie swoje #1/#2/#3.
- [ ] `selectedScenarioKey` i `selectedVariantKey` są jawne i stabilne; zmiana scenariusza wybiera jego #1.
- [ ] Nowy render i wysyłka nie są oparte na globalnym, spłaszczonym `rankedPlans`.
- [ ] Sekcja `Propozycja składu`, `Alternatywy` i globalne `Szczegóły najlepszego wariantu` nie są renderowane.
- [ ] Parametry wejściowe są kompaktowe i rozwijane; wynik jest wysoko na stronie.
- [ ] `VariantLineupComposer` jest jednym źródłem pełnej siódemki, bench, VM ID i `send_blockers`.
- [ ] Mapowanie dwóch slotów tej samej pozycji ma test regresyjny.
- [ ] Każdy legalny Wariant ma pełną siódemkę i model dokładnie pięciu miejsc ławki.
- [ ] UI wskazuje `bazowy` / `optymalizowany` dla starterów.
- [ ] #2/#3 pokazują różnicę względem #1.
- [ ] Aktywny panel pokazuje `player_results` aktywnego Wariantu.
- [ ] `send_blockers` wskazują konkretne przyczyny i konkretne osoby/sloty.
- [ ] Wariant niepełny albo wymagający ponad pięciu rezerwowych nie może zostać wysłany.
- [ ] Wariant z `substitutions_count === 0` pozostaje widoczny i zapisuje pełną taktykę bez reguł zmian.
- [ ] VM otrzymuje dokładnie `starter_vm_player_ids` i `bench_vm_player_ids` z composera; bench nie jest rekonstruowany niezależnie z `playerIn`.
- [ ] Modal zastosowania Wariantu podsumowuje scenariusz, rangę, starterów, bench i liczbę zmian.
- [ ] Stary UI safety/worst-case nie sugeruje wspólnego rankingu, jeżeli algorytm nadal działa niezależnie per scenariusz.
- [ ] Przyszłe wymuszenia zawodników pozostają niewdrożone, ale model może je później przyjąć jako ograniczenie wejściowe.
- [ ] Testy focused i regresyjne wykonane; każda baseline failure została potwierdzona względem stanu sprzed zmian.
- [ ] `vendor/bin/pint --dirty --format agent` przechodzi.
- [ ] `CONTEXT.md`, `Progress`, `Surprises & Discoveries`, `Decision Log` i `Outcomes & Retrospective` są zaktualizowane zgodnie z faktycznie wykonanym zakresem.

Plan Change Note: 2026-09-09 — pierwotny plan został poddany review względem `OPTIMIZER_RESULT_UI_GUIDE.md`. Zmieniono architekturę z płaskiego `rankedPlans` na scenario-first, dodano `selectedScenarioKey`, `VariantLineupComposer`, jawne `send_blockers`, różnice #2/#3 względem #1, `player_results` aktywnego Wariantu, compact input summary, weryfikację `scenarioSafetyMode`, wspólny model lineup/bench dla UI i VM oraz pełny rollback przez revert. Cofnięto wcześniejszą decyzję o odfiltrowaniu Wariantów z `substitutions_count === 0`: taki Wariant pozostaje legalny i może zapisać samą pełną taktykę.