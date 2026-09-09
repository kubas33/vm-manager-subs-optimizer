# Optimizer Result UI Redesign Guide

## Cel dokumentu

Ten dokument opisuje docelową przebudowę ekranu `optimizer/result` tak, aby UI odpowiadało rzeczywistemu modelowi domenowemu optymalizatora i jasno pokazywało użytkownikowi:

1. jaki scenariusz wyniku meczu jest aktualnie analizowany,
2. jakie warianty planu zmian są dostępne dla tego scenariusza,
3. jaki **pełny skład** zostanie faktycznie zapisany w VM Managerze dla wybranego wariantu,
4. jaka ławka jest wymagana przez wariant,
5. jakie zmiany zostaną wykonane,
6. jaki jest efekt treningowy wariantu,
7. dlaczego wariant #1 jest rekomendowany względem #2 i #3.

Dokument jest jednocześnie specyfikacją UX oraz przewodnikiem implementacyjnym.

---

# 1. Problem obecnego UI

Aktualny ekran miesza dwa różne mechanizmy:

- `LineupRecommendationService` — buduje pełny bazowy skład 7 zawodników, zasadniczo na podstawie najniższych pasków treningowych,
- `TrainingOptimizerService` + `SubstitutionPlanGenerator` — generują i oceniają warianty starterów oraz zmian dla pozycji wybranych do optymalizacji.

Na początku strony pokazywany jest obecnie `Skład główny` jako rekomendacja. Niżej pojawiają się jednak warianty optymalizatora, które mogą mieć innych starterów na analizowanych pozycjach.

Powoduje to niejasność:

> Czy skład pokazany na górze jest składem, który zostanie użyty dla wariantu #1, #2 albo #3?

Technicznie odpowiedź brzmi: **częściowo**.

Przy wysyłaniu wariantu do VM Managera bazowy skład jest używany do wypełnienia pozycji nieobjętych optymalizacją, natomiast starterzy wynikający z wybranego wariantu zastępują odpowiednie miejsca na analizowanych pozycjach.

Dlatego obiektem prezentowanym użytkownikowi powinien być nie osobny `Skład główny`, lecz:

> **kompletny wariant meczowy = bazowy skład dla nieanalizowanych pozycji + starterzy wariantu + ławka wariantu + plan zmian**.

---

# 2. Model domenowy, który UI musi odzwierciedlać

Źródłem terminologii pozostaje `CONTEXT.md`.

Najważniejsze relacje:

- jeden **Scenariusz wyniku meczu** ma wiele **Wariantów planu zmian**,
- każdy scenariusz ma własny ranking wariantów,
- wariant #1 w danym scenariuszu jest jego **Rekomendacją zmian**,
- rekomendacja dla 3:0 nie jest rekomendacją dla 3:1 ani 3:2,
- standardowy mecz może mieć osobne rankingi dla 3:0, 3:1 i 3:2,
- trudny mecz może mieć osobne rankingi dla 3:1 i 3:2.

UI nie powinno tworzyć pojęcia globalnego `najlepszego wariantu`, jeżeli ranking jest liczony niezależnie dla scenariuszy.

## Docelowa hierarchia informacji

```text
Wynik optymalizacji
└── Scenariusz wyniku meczu
    ├── Wariant #1 — rekomendowany
    ├── Wariant #2
    └── Wariant #3
        ├── pełny skład podstawowy
        ├── ławka
        ├── plan zmian
        └── efekt treningowy
```

To jest podstawowa zasada całej przebudowy.

---

# 3. Główna zmiana UX: scenario-first

Obecnie wszystkie warianty są spłaszczane wizualnie do jednej długiej listy.

Docelowo pierwszym poziomem wyboru powinien być **scenariusz wyniku meczu**.

Przykład dla standardowego meczu:

```text
[ Standardowe 3:0 ] [ Standardowe 3:1 ] [ Standardowe 3:2 ]
```

Każdy tab / segment zawiera własne:

```text
#1 Rekomendowany
#2
#3
```

### Preferowane zachowanie

- domyślnie aktywny jest pierwszy scenariusz,
- domyślnie zaznaczony jest wariant #1,
- zmiana scenariusza automatycznie wybiera jego wariant #1,
- zmiana wariantu aktualizuje jeden wspólny panel szczegółów poniżej,
- nie renderować jednocześnie pełnych szczegółów wszystkich wariantów.

### Dlaczego

Użytkownik odpowiada najpierw na pytanie:

> Co mam zrobić, jeśli mecz zakończy się według tego scenariusza?

Dopiero później:

> Który z trzech planów dla tego scenariusza chcę zastosować?

---

# 4. Docelowy układ strony

## 4.1 Header

Header powinien być niewielki.

```text
Wynik optymalizacji

[R + 2x Ś]  [próg 20%]  [3 scenariusze]  [Liga ▼]

[Zmień parametry] [Więcej ▾]
```

Nie należy rozpoczynać strony od dużej karty `Propozycja składu`.

### Akcje

- `Zmień parametry` → powrót do `optimizer.create`,
- `Typ meczu w grze` pozostaje dostępny przed akcją wysyłania,
- `Usuń wszystkie zmiany` powinno być akcją drugorzędną/destrukcyjną, najlepiej w menu `Więcej`, a nie jednym z dominujących CTA.

---

## 4.2 Skrócone podsumowanie parametrów

Obecne sekcje:

- Pozycje,
- Tryb wejścia,
- Scenariusze,
- Pule rezerwowych,
- Znormalizowane scenariusze,

zawierają głównie parametry, które użytkownik dopiero co ustawił.

Nie powinny odsuwać właściwego wyniku o kilka ekranów w dół.

Docelowo pokazać skrót, np.:

```text
Rozgrywający + 2x Środkowy · próg 20% · 5 miejsc rezerwowych · Standardowy
```

oraz link/przycisk:

```text
[Pokaż parametry wejściowe]
```

Po rozwinięciu można zachować większość obecnych danych.

---

## 4.3 Nawigacja scenariuszy

Przykład:

```text
SCENARIUSZE MECZU

[ Standardowe 3:0 ] [ Standardowe 3:1 ] [ Standardowe 3:2 ]
      3 warianty          3 warianty          3 warianty
```

Po wybraniu:

```text
Standardowe 3:0
25:20, 25:18, 25:22
3 sety · 135 akcji
```

Nie używać terminu `wariant` dla wyniku meczu. `Wariant` jest zarezerwowany dla planu zmian.

---

# 5. Porównanie wariantów

Warianty #1–#3 powinny być łatwe do porównania bez rozwijania wielu dużych kart.

Preferowana forma: trzy kompaktowe karty albo tabela porównawcza.

Przykład:

```text
┌────────────────────────┐
│ #1  REKOMENDOWANY      │
│ +84 treningu           │
│ min. pasek 27%         │
│ 0 poniżej progu        │
│ 0 zmarnowanych akcji   │
│ 4 zmiany               │
└────────────────────────┘

┌────────────────────────┐
│ #2                     │
│ +82 treningu           │
│ min. pasek 31%         │
│ 0 poniżej progu        │
│ 2 zmarnowane akcje     │
│ 3 zmiany               │
└────────────────────────┘
```

Kliknięcie karty ustawia aktywny wariant.

## Metryki i kolejność

UI powinno prezentować metryki zgodnie z rzeczywistą logiką rankingu `TrainingOptimizerService`:

1. `total_gained_training` — większy jest lepszy,
2. `players_below_fairness_threshold` — mniejszy jest lepszy,
3. profil końcowych pasków / wyrównanie,
4. `wasted_actions` — mniej jest lepiej,
5. `substitutions_count` — mniej jest lepiej przy wcześniejszym remisie.

Nie wszystkie metryki muszą mieć taki sam wizualny ciężar.

### Zalecany priorytet wizualny

Najmocniej:

- przyrost treningu,
- zawodnicy poniżej progu,
- najniższy końcowy pasek.

Drugorzędnie:

- zmarnowane akcje,
- liczba zmian.

---

# 6. Różnice między wariantami

Wariant #2 i #3 powinny jasno pokazywać, czym różnią się od rekomendowanego #1.

Przykłady:

```text
Różnica vs #1:
R: Kowalski → Nowak
Ś2: Wiśniewski → Zieliński
```

albo krócej:

```text
2 innych starterów · 1 inna reguła zmiany
```

To jest bardziej użyteczne niż zmuszanie użytkownika do ręcznego porównywania kilku dużych bloków.

Warto przygotować helper/view-model wyliczający różnice między planami.

---

# 7. Pełny skład wybranego wariantu

To jest najważniejsza nowa sekcja.

Dla wybranego wariantu UI musi pokazywać **dokładnie tę siódemkę, która zostanie zapisana w VM Managerze**.

## Skład powinien łączyć dwa źródła

1. bazowy `LineupRecommendationService` dla pozycji poza optymalizacją,
2. starterów z `rankedPlan['plan']['slots']` dla pozycji analizowanych.

Nie należy powielać tej logiki niezależnie w widoku i w metodzie wysyłającej.

### Zalecana zmiana architektoniczna

Wydzielić odpowiedzialność do serwisu, np.:

```php
final class VariantLineupComposer
{
    public function compose(array $baseRecommendation, array $plan): array
    {
        // ...
    }
}
```

Nazwa może zostać dostosowana do konwencji projektu.

Serwis powinien być źródłem prawdy zarówno dla:

- UI,
- zapisu taktyki do VM Managera.

W ten sposób obowiązuje gwarancja:

> **what you see is what gets pushed**.

Nie utrzymywać osobnej wersji algorytmu składania siódemki w Blade/Livewire.

---

# 8. Wizualizacja pełnej siódemki

Można zachować obecny układ boiska 3x3 z libero pod spodem, ale wykorzystać go dla aktywnego wariantu.

Przykład:

```text
         Ś1
At                 P1

P2                 Ś2
         R

         L
```

Każdy zawodnik powinien zawierać minimum:

- skrót pozycji,
- nazwę,
- aktualny pasek treningowy,
- oznaczenie źródła.

### Oznaczenie źródła zawodnika

Rozróżnić:

- `bazowy` — pozycja nieobjęta optymalizacją,
- `optymalizowany` — starter pochodzi z aktywnego wariantu.

Nie opierać znaczenia wyłącznie na kolorze. Kolor może być dodatkiem do badge/tekstu.

Przykład:

```text
Kowalski
R · 12%
[optymalizowany]
```

Pozwala to użytkownikowi od razu zrozumieć, dlaczego skład zmienia się pomiędzy wariantami.

---

# 9. Ławka wariantu

Obok pełnego składu lub bezpośrednio pod nim pokazać rezerwowych wymaganych przez plan.

```text
ŁAWKA

Nowak        R    7%
Zieliński    Ś   14%
...
```

Źródłem pozostaje logika odpowiadająca obecnemu `variantBenchPlayers()`.

Należy jasno zaznaczyć, że jest to:

> ławka wymagana przez **ten konkretny wariant**, a nie ogólna lista rezerwowych z bazy.

Jeżeli wariant nie wymaga rezerwowych, wyświetlić prosty komunikat:

```text
Ten wariant nie wymaga dodatkowych zawodników na ławce.
```

---

# 10. Plan zmian

Obecny widok grupuje szczegóły według slotów i wypisuje opisy każdego seta.

Docelowo podstawową reprezentacją powinien być bardziej czytelny plan meczowy.

Przykład:

```text
PLAN ZMIAN

Set 1
R: Kowalski → Nowak przy 1 pkt
Ś1: bez zmiany

Set 2
R: bez zmiany
Ś1: Zieliński → Wiśniewski przy 1 pkt

Set 3
...
```

Możliwa jest również tabela:

| Set | Pozycja | Starter | Zmiana | Moment |
|---|---|---|---|---|
| 1 | R | Kowalski | Nowak | 1 pkt |
| 1 | Ś | Zieliński | — | — |

Nie pokazywać dużych kart dla slotów, jeżeli wszystkie dane można odczytać szybciej z tabeli/listy.

---

# 11. Efekt treningowy wariantu

Dla aktywnego wariantu pokazać jedną tabelę zawodników zamiast osobnego panelu `Szczegóły najlepszego wariantu`.

Przykład:

| Zawodnik | Pozycja | Start | Akcje | Zysk | Koniec | Strata |
|---|---|---:|---:|---:|---:|---:|
| Kowalski | R | 12% | 15 | +8% | 20% | 0 |
| Nowak | R | 7% | 60 | +24% | 31% | 0 |

### Ważne

Tabela dotyczy **aktualnie wybranego wariantu aktualnie wybranego scenariusza**.

Nie używać już konstrukcji:

```php
$bestPlan = $this->rankedPlans[0];
```

jako źródła globalnego `najlepszego wariantu`.

W modelu scenario-first taki globalny obiekt nie ma poprawnego znaczenia domenowego.

---

# 12. Akcja wysyłania wariantu

Główne CTA powinno brzmieć np.:

```text
[Wyślij ten wariant do VM Managera]
```

albo krócej:

```text
[Zastosuj wariant]
```

Po kliknięciu modal potwierdzenia powinien podsumować:

- typ meczu,
- scenariusz,
- numer wariantu,
- 7 starterów,
- liczbę rezerwowych,
- liczbę reguł zmian.

Użytkownik powinien dokładnie wiedzieć, co zostanie zapisane.

---

# 13. Ważna poprawka: wariant bez zmian

Silnik może poprawnie zwrócić najlepszy wariant z:

```text
substitutions_count = 0
```

Taki wariant nadal może mieć właściwy skład startowy do zapisania.

Obecna logika UI uzależnia możliwość wysłania wariantu od obecności zmian i `pushSubstitutions()` kończy działanie, gdy payload zmian jest pusty.

To powinno zostać przebudowane.

## Docelowo

Akcja powinna odpowiadać operacji na całym wariancie, np.:

```php
applyVariant(int $scenarioIndex, int $variantIndex)
```

lub równoważnej.

Przebieg:

1. zbudować pełny skład wariantu,
2. zapisać pełną taktykę/skład do VM Managera,
3. zbudować reguły zmian,
4. jeżeli istnieją — zapisać je,
5. jeżeli ich nie ma — operacja nadal jest poprawnym sukcesem.

Termin `pushSubstitutions` jest za wąski względem faktycznej operacji.

---

# 14. `LineupRecommendationService` po przebudowie

Nie usuwać serwisu tylko dlatego, że `Skład główny` znika z góry ekranu.

Serwis nadal jest potrzebny do utworzenia bazowej siódemki dla pozycji nieobjętych optymalizacją.

Zmienia się jego rola w UI:

### obecnie

```text
Propozycja składu
└── Skład główny — Rekomendowany
```

### docelowo

```text
wewnętrzna baza składu
        +
starterzy aktywnego wariantu
        =
pełny skład wariantu
```

Opcjonalnie bazowy skład można udostępnić w sekcji diagnostycznej:

```text
[Pokaż sposób zbudowania składu]
```

ale nie powinien konkurować wizualnie z właściwym wynikiem optymalizacji.

---

# 15. `scenarioSafetyMode`

Przy implementacji należy ponownie zweryfikować znaczenie `scenarioSafetyMode`.

Aktualny ekran zawiera prezentację `najgorszego scenariusza`, natomiast `scenarioRankings()` liczy ranking osobno dla każdego `MatchScenario` przez `optimize()`.

Jeżeli docelowym modelem pozostaje zapisany w `CONTEXT.md` model niezależnych rankingów 3:0 / 3:1 / 3:2, UI nie powinno sugerować wspólnego rankingu odpornego na kilka scenariuszy, jeśli nie jest on faktycznie używany w bieżącej ścieżce.

Przed przebudową tej części należy zdecydować jedną z dwóch opcji:

1. **scenario-first pozostaje jedynym modelem** — usunąć martwe/nieadekwatne elementy safety-mode z result UI,
2. safety mode ma rzeczywiście oceniać jeden plan w wielu scenariuszach — wtedy wymaga osobnego modelu UX i wyraźnego rozróżnienia od niezależnych rankingów.

Nie mieszać obu modeli na jednym ekranie bez jasnego znaczenia.

---

# 16. Stan komponentu Livewire

Zamiast spłaszczonego `rankedPlans` UI powinno operować strukturą scenariuszową.

Przykładowy stan:

```php
public int $selectedScenarioIndex = 0;
public int $selectedVariantIndex = 0;
```

oraz computed properties w stylu:

```php
#[Computed]
public function selectedScenarioRanking(): ?array
{
    // ...
}

#[Computed]
public function selectedVariant(): ?array
{
    // ...
}

#[Computed]
public function selectedVariantLineup(): array
{
    // VariantLineupComposer
}
```

Nazwy są przykładowe i powinny być dopasowane do finalnej implementacji.

### Ważne

`scenarioRankings` już ma strukturę znacznie bliższą docelowemu UI niż `rankedPlans`:

```text
scenario
├── label
├── input
├── sets_count
└── plans[]
```

Warto oprzeć renderowanie właśnie na tej strukturze i ograniczyć zależność UI od spłaszczonego `rankedPlans`.

---

# 17. Podział widoku na mniejsze części

`resources/views/pages/optimizer/⚡result.blade.php` jest obecnie bardzo duży.

Przy przebudowie nie dokładać kolejnych dużych bloków bezpośrednio do jednego pliku.

Preferowany podział na komponenty/partials zgodny z istniejącymi konwencjami projektu, np. logicznie:

```text
result
├── header / input-summary
├── scenario-selector
├── variant-comparison
├── variant-lineup
├── variant-bench
├── substitution-plan
├── training-results
└── apply-variant-modal
```

Nie jest wymagane odwzorowanie 1:1 na osobne pliki. Podział ma ograniczyć odpowiedzialność głównego widoku i duplikację markup.

Przed implementacją należy sprawdzić istniejące komponenty Flux i konwencje w `resources/views/components`.

---

# 18. Responsywność

## Desktop

Preferowany układ szczegółów wariantu:

```text
┌───────────────────────┬──────────────────┐
│ pełny skład / boisko  │ ławka            │
└───────────────────────┴──────────────────┘

┌──────────────────────────────────────────┐
│ plan zmian                               │
└──────────────────────────────────────────┘

┌──────────────────────────────────────────┐
│ efekt treningowy                         │
└──────────────────────────────────────────┘
```

## Mobile

Sekcje układają się pionowo:

```text
scenariusz
warianty
skład
ławka
plan zmian
efekt treningowy
CTA
```

Porównanie wariantów powinno działać bez poziomego scrollowania całej strony. Dopuszczalny jest poziomy scroll wyłącznie dla lokalnej tabeli, jeżeli jest konieczny.

---

# 19. Stany szczególne

Nowe UI musi jawnie obsłużyć:

## Brak danych wejściowych

CTA:

```text
Przejdź do konfiguracji optymalizacji
```

## Brak aktywnych zawodników

Wyjaśnić problem i podać link do zarządzania zawodnikami.

## Niekompletny bazowy skład

Pokazać brakujące pozycje.

Wariant może istnieć dla analizowanych pozycji, ale pełna taktyka nie może być wysłana bez kompletnej siódemki.

## Brak ID VM

Zaznaczyć konkretnych zawodników bez `vm_player_id`, a nie tylko ogólny badge `Brak ID VM`.

## Brak legalnych wariantów

Wyjaśnić, dla którego scenariusza/pozycji brakuje kandydatów.

## Wariant bez zmian

Pokazać:

```text
Plan nie wymaga zmian w trakcie meczu.
```

ale nadal umożliwić zapis składu, jeżeli pełna siódemka jest poprawna.

---

# 20. Kolejność implementacji

## Etap 1 — uporządkowanie modelu prezentacyjnego

1. pozostawić `scenarioRankings` jako główną strukturę dla UI,
2. dodać wybór scenariusza i wariantu,
3. usunąć założenie globalnego `bestPlan`,
4. przygotować helper/view-model różnic pomiędzy wariantami.

## Etap 2 — pełny skład wariantu

1. wydzielić składanie pełnej siódemki z obecnej logiki `starterVmPlayerIdsForPlan()`,
2. stworzyć współdzielony serwis/composer,
3. użyć go w UI,
4. użyć go w integracji z VM Managerem.

## Etap 3 — nowe UI

1. kompaktowy header,
2. zwinięte parametry wejściowe,
3. scenario selector,
4. comparison wariantów,
5. jeden panel szczegółów aktywnego wariantu,
6. pełny skład + ławka,
7. plan zmian,
8. tabela efektu treningowego.

## Etap 4 — akcja `apply variant`

1. zastąpić semantykę `pushSubstitutions` operacją całego wariantu,
2. zapisywać skład także dla wariantu z 0 zmian,
3. wysyłać reguły zmian tylko jeśli istnieją,
4. poprawić modal potwierdzenia i komunikaty statusu.

## Etap 5 — cleanup

1. usunąć stary panel `Propozycja składu` z głównej hierarchii,
2. usunąć `Szczegóły najlepszego wariantu`,
3. usunąć lub ograniczyć `rankedPlans` w warstwie UI, jeśli przestanie być potrzebne,
4. zweryfikować `scenarioSafetyMode`,
5. podzielić duży widok na mniejsze części.

---

# 21. Kryteria akceptacji

Implementację można uznać za zakończoną, jeśli spełnione są wszystkie poniższe punkty.

## Model scenariuszy

- [ ] Każdy scenariusz 3:0 / 3:1 / 3:2 ma osobny widoczny ranking.
- [ ] Wariant #1 jest oznaczony jako rekomendowany wyłącznie w ramach swojego scenariusza.
- [ ] UI nie używa pojęcia globalnego `najlepszego wariantu`.

## Porównanie

- [ ] #1, #2 i #3 można porównać bez otwierania trzech dużych bloków szczegółów.
- [ ] Widoczne są główne metryki rankingu.
- [ ] Widać najważniejsze różnice składu/planu względem #1.

## Skład

- [ ] Aktywny wariant pokazuje pełnych 7 starterów.
- [ ] Skład odpowiada dokładnie temu, co zostanie wysłane do VM Managera.
- [ ] Widać, które pozycje pochodzą z bazowego składu, a które zostały wybrane przez optymalizator.
- [ ] Widać ławkę wymaganą przez wybrany wariant.

## Zmiany

- [ ] Plan zmian jest czytelny per set.
- [ ] Wariant z 0 zmian ma poprawny stan UI.
- [ ] Wariant z 0 zmian może zapisać pełny skład.

## Efekt treningowy

- [ ] Wyniki zawodników dotyczą aktywnego scenariusza i aktywnego wariantu.
- [ ] Nie istnieje stały panel szczegółów `rankedPlans[0]` niezależny od aktualnego wyboru.

## Integracja VM

- [ ] Modal przed wysłaniem pokazuje pełny zakres operacji.
- [ ] Pełny skład widoczny przed kliknięciem odpowiada payloadowi wysyłanemu do VM.
- [ ] Brak `vm_player_id` wskazuje konkretnych problematycznych zawodników.

## UX

- [ ] Właściwe wyniki są widoczne bez przewijania przez kilka dużych sekcji parametrów wejściowych.
- [ ] Widok jest czytelny na desktopie i mobile.
- [ ] Destrukcyjne `Usuń wszystkie zmiany` nie konkuruje wizualnie z głównym CTA wariantu.

---

# 22. Testy wymagane przy implementacji

Przebudowa nie powinna być traktowana jako wyłącznie zmiana wizualna, ponieważ zmienia sposób składania i stosowania wariantu.

Minimalnie należy pokryć testami:

1. scenariusz ma własne 3 warianty i #1 jest poprawnie wybierany,
2. zmiana scenariusza wybiera właściwy ranking,
3. pełny skład wariantu zastępuje tylko pozycje objęte planem,
4. pozycje nieobjęte optymalizacją pozostają z bazowej rekomendacji,
5. ten sam composer jest używany do prezentacji i wysłania taktyki,
6. wariant z `substitutions_count = 0` nadal zapisuje skład,
7. wariant ze zmianami zapisuje skład, ławkę i zmiany,
8. brak `vm_player_id` blokuje wysłanie i wskazuje problem,
9. przełączenie #1 → #2 aktualizuje pełny skład, ławkę, plan i wyniki,
10. UI nie pokazuje danych wariantu poprzedniego scenariusza po zmianie taba.

W przypadku testów Livewire sprawdzić stan komponentu i akcje. Dla krytycznego flow warto dodać test przeglądarkowy/smoke test, jeśli repo ma już taki wzorzec.

---

# 23. Pliki, których zmiana jest najbardziej prawdopodobna

Główne:

```text
resources/views/pages/optimizer/⚡result.blade.php
app/LineupRecommendationService.php
app/TrainingOptimizerService.php
app/VmTacticsService.php
app/VmSubstitutionService.php
```

Prawdopodobnie nowy mały serwis odpowiedzialny za złożenie pełnego składu wariantu.

Dodatkowo odpowiednie testy dla result page / optimizer / VM integration.

Nie należy zmieniać algorytmu rankingu tylko po to, aby uprościć UI. Celem jest najpierw poprawne odwzorowanie istniejącej domeny.

---

# 24. Zasady końcowe

1. **Scenariusz jest nadrzędny względem wariantu.**
2. **Każdy scenariusz ma własną rekomendację #1.**
3. **Pełny skład wariantu jest ważniejszy niż osobny bazowy `Skład główny`.**
4. **Użytkownik musi widzieć dokładnie to, co zostanie zapisane w VM Managerze.**
5. **Wariant to kompletna decyzja meczowa: skład + ławka + zmiany + efekt treningowy.**
6. **Parametry wejściowe są kontekstem, nie główną treścią result page.**
7. **Porównanie wariantów powinno być szybkie; szczegóły renderowane tylko dla aktywnego wariantu.**
8. **Nie używać globalnego `bestPlan`, jeżeli rankingi są niezależne per scenariusz.**
9. **Wariant bez zmian jest prawidłowym wariantem i nadal może wymagać zapisania innego składu.**
10. **Logika składania pełnej siódemki nie może być zduplikowana pomiędzy UI i integracją VM.**
