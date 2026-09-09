# Optymalizacja zmian meczowych

Kontekst opisuje planowanie zmian zawodników tak, aby wykorzystać przebieg meczu do możliwie korzystnego rozwoju treningowego drużyny.

## Language

**Scenariusz wyniku meczu**:
Możliwy zwycięski wynik naszej drużyny określający liczbę i przebieg setów, na przykład 3:0, 3:1 albo 3:2.
_Avoid_: Wariant, plan

**Wariant planu zmian**:
Jedna dopuszczalna propozycja starterów i zmian zawodników w ramach konkretnego scenariusza wyniku meczu.
_Avoid_: Scenariusz meczu

**Rekomendacja zmian**:
Najwyżej oceniony wariant planu zmian dla jednego scenariusza wyniku meczu.
_Avoid_: Wspólny plan dla wszystkich wyników

**Łatwy mecz**:
Mecz, dla którego zakładanym scenariuszem wyniku jest wyłącznie 3:0.
_Avoid_: Zakres wyników 3:0–3:2

**Standardowy mecz**:
Mecz rozpatrywany niezależnie w scenariuszach wyniku 3:0, 3:1 i 3:2.
_Avoid_: Pojedynczy wynik referencyjny

**Trudny mecz**:
Mecz rozpatrywany niezależnie w scenariuszach wyniku 3:1 i 3:2.
_Avoid_: Scenariusz 3:0, wyłącznie 3:2

## Relationships

- Jeden **Scenariusz wyniku meczu** ma wiele **Wariantów planu zmian**
- Jeden **Scenariusz wyniku meczu** ma własną **Rekomendację zmian**
- **Rekomendacja zmian** nie jest współdzielona pomiędzy scenariuszami 3:0, 3:1 i 3:2
- **Łatwy mecz** ma dokładnie jeden **Scenariusz wyniku meczu**: 3:0
- **Standardowy mecz** ma trzy niezależne **Scenariusze wyniku meczu**: 3:0, 3:1 i 3:2
- **Trudny mecz** ma dwa niezależne **Scenariusze wyniku meczu**: 3:1 i 3:2
- Automatyczne **Scenariusze wyniku meczu** opisują zwycięstwo naszej drużyny, nie porażkę
- Każdy **Scenariusz wyniku meczu** prezentuje trzy najwyżej ocenione **Warianty planu zmian**, a pierwszy jest **Rekomendacją zmian**
- Skład podstawowy i ławka rezerwowych są właściwościami konkretnego **Wariantu planu zmian** i muszą być widoczne razem z jego definicjami zmian
- Niezależna, ogólna „Propozycja składu” nie jest źródłem składu ani ławki dla **Wariantu planu zmian** i nie jest prezentowana na ekranie wyniku optymalizacji
- Każdy **Wariant planu zmian** prezentuje pełną siódemkę: zawodników z wariantu na analizowanych pozycjach oraz deterministyczne uzupełnienie pozycji nieanalizowanych składem bazowym
- Skład bazowy dla pozycji nieanalizowanych pochodzi z rekomendacji najniższych pasków treningowych, a nie z odczytu bieżącej taktyki VM
- Przyszły mechanizm ręcznego wymuszenia zawodnika na pozycji jest osobnym feature'em i nie należy go implementować w tym zakresie; obecny model powinien pozwolić dodać takie wymuszenie jako ograniczenie optymalizacji bez podmiany wariantu po jego wyliczeniu
- Przyszłe ręczne wymuszenie zawodnika ma obowiązywać we wszystkich Wariantach planu zmian jako ograniczenie wejściowe optymalizacji dla wskazanej pozycji
- Lista Wariantów planu zmian służy do wyboru, a jeden dynamiczny panel pokazuje pełny skład, ławkę i definicje zmian wybranego wariantu zamiast powielać te dane w każdej karcie
- Wariant planu zmian bez żadnej rzeczywistej zmiany nie jest obliczany ani prezentowany; pojedynczy slot może jednak pozostać bez rezerwowego i bez zmiany, jeśli wynika to z limitu tego slotu
- Wysyłka jest dostępna dla każdego poprawnego, zaprezentowanego Wariantu planu zmian i zapisuje jego pełną taktykę oraz reguły zmian
- Ławka VM ma zawsze dokładnie pięć możliwych miejsc; suma limitów rezerwowych w konfiguracji wejściowej nie może przekroczyć pięciu, a warianty wymagające większej ławki nie są legalnym wynikiem optymalizacji
- Pozycja z limitem rezerwowych `0` nadal ma startera w każdym pełnym wariancie, ale nie generuje dla tej pozycji zmian ani zawodników na ławce

## Example dialogue

> **Dev:** „Czy plan wybrany dla 3:2 pokazujemy także jako najlepszy dla 3:0?”
> **Domain expert:** „Nie — każdy scenariusz wyniku meczu ma osobny ranking i własną rekomendację zmian.”
>
> **Dev:** „Czy dla łatwego meczu liczymy zabezpieczenie na pięć setów?”
> **Domain expert:** „Nie — łatwy mecz zakładamy jako 3:0.”

## Flagged ambiguities

- „Wariant” był używany zarówno dla wyniku meczu, jak i planu zmian — rozróżniamy **Scenariusz wyniku meczu** oraz **Wariant planu zmian**.
