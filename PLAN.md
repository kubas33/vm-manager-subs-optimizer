# VM Manager Subs Optimizer - Plan

## Cel aplikacji

Lokalna aplikacja webowa oparta o Laravel, ktora:
- przechowuje baze zawodnikow i ich aktualny pasek treningowy,
- pozwala wybrac 2 pozycje analizowane przed meczem,
- wyznacza rekomendowany pierwszy sklad dla tych 2 slotow,
- proponuje optymalny plan zmian w trakcie meczu,
- maksymalizuje koncowy poziom paska treningowego po meczu,
- minimalizuje straty wynikajace z niewykorzystanych lub zmarnowanych akcji.

## Zalozenia MVP

- Analiza dotyczy dokladnie 2 miejsc na boisku.
- Kazdy zawodnik ma przypisana pozycje.
- Zmiany moga odbywac sie tylko w obrebie tej samej pozycji.
- Kazdy set rozpoczynaja zawodnicy wybrani do pierwszego skladu.
- Na start dopuszczamy maksymalnie 1 wejscie zawodnika na set dla kazdego analizowanego slotu.
- Pasek treningowy:
  - zawodnik zdobywa 1% za kazda akcje rozegrana na boisku,
  - w jednym meczu moze zdobyc maksymalnie 50%,
  - nie moze przekroczyc 100% paska,
  - realny limit zysku to `min(50, 100 - aktualny_pasek)`.
- Aplikacja powinna obslugiwac:
  - standardowy zestaw scenariuszy meczu,
  - pojedynczy scenariusz wpisany recznie,
  - kilka scenariuszy naraz.

## Decyzje technologiczne

- Backend: Laravel
- Frontend: Blade + Livewire
- Baza danych: MySQL
- Srodowisko lokalne: Laravel Sail
- Stylowanie: na start podstawowy layout Blade z prostym, czytelnym UI

## Glowny workflow uzytkownika

1. Uzytkownik dodaje lub edytuje zawodnikow w bazie.
2. Uzytkownik ustawia aktualne wartosci paskow treningowych.
3. Uzytkownik wybiera 2 pozycje, ktore chce zoptymalizowac.
4. Uzytkownik wybiera tryb analizy:
   - preset scenariuszy,
   - jeden scenariusz reczny,
   - kilka scenariuszy recznych.
5. System generuje:
   - rekomendowany pierwszy sklad,
   - rekomendowanych rezerwowych,
   - plan zmian dla obu slotow,
   - ranking najlepszych wariantow,
   - przewidywany efekt koncowy.

## Ekrany MVP

### 1. Lista zawodnikow

Funkcje:
- lista wszystkich zawodnikow,
- filtrowanie po pozycji,
- szybka edycja nazwiska, pozycji i paska,
- oznaczenie zawodnika jako aktywny lub nieaktywny,
- formularz dodawania nowego zawodnika.

### 2. Ekran optymalizacji

Funkcje:
- wybor 2 pozycji do analizy,
- wybor trybu scenariusza,
- wprowadzanie wynikow setow,
- uruchomienie optymalizacji.

### 3. Ekran wyniku

Funkcje:
- rekomendowany pierwszy sklad dla obu slotow,
- rekomendowani zmiennicy,
- plan zmian zapisany w formie zrozumialej do ustawienia w grze,
- tabela z koncowym paskiem kazdego zawodnika,
- top wariantow z punktacja i stratami.

## Model danych

### Tabela `players`

Pola:
- `id`
- `name`
- `position`
- `training_bar`
- `active`
- `created_at`
- `updated_at`

Uwagi:
- `training_bar` jako liczba calkowita 0-100,
- `position` jako enum lub string kontrolowany przez aplikacje.

### Opcjonalne tabele w kolejnym etapie

- `optimization_runs`
- `optimization_results`
- `scenario_presets`

Na start mozna policzyc wszystko w locie bez zapisu historii analiz.

## Logika domenowa

### Glowne obiekty / serwisy

- `Player`
- `MatchScenario`
- `ScenarioSet`
- `TrainingGainCalculator`
- `SubstitutionPlanGenerator`
- `TrainingOptimizerService`

### Odpowiedzialnosci

- `TrainingGainCalculator`
  - liczy, ile paska zawodnik moze jeszcze zyskac,
  - pilnuje limitu 50 na mecz oraz limitu 100 calkowitego paska.

- `MatchScenario`
  - reprezentuje pojedynczy przebieg meczu,
  - zawiera liste setow i ich wyniki.

- `ScenarioSet`
  - reprezentuje pakiet scenariuszy do analizy lacznej,
  - pozwala przypisac wagi lub liczyc sredni wynik.

- `SubstitutionPlanGenerator`
  - generuje dozwolone warianty zmian dla dwoch slotow,
  - uwzglednia tylko zawodnikow z tej samej pozycji,
  - respektuje limit jednego wejscia na set.

- `TrainingOptimizerService`
  - ocenia wszystkie dopuszczalne warianty,
  - wybiera najlepszy wariant lub ranking najlepszych wariantow.

## Algorytm MVP

1. Pobierz aktywnych zawodnikow dla 2 wybranych pozycji.
2. Zbuduj kandydatow na starterow i zmiennikow osobno dla kazdej pozycji.
3. Wygeneruj mozliwe plany zmian:
   - starter na set,
   - opcjonalna zmiana w secie od zadanego progu punktowego,
   - maksymalnie jedno wejscie na set dla danego slotu.
4. Dla kazdego scenariusza meczu policz:
   - liczbe akcji przypadajacych na zawodnika,
   - przyrost paska dla zawodnika,
   - pasek koncowy,
   - liczbe zmarnowanych akcji ponad limit.
5. Dla zestawu scenariuszy policz wynik laczny:
   - srednia lub suma wazona koncowych paskow,
   - kara za zmarnowane akcje.
6. Posortuj warianty i zwroc najlepsze.

## Tryby scenariuszy

### 1. Preset

Wstepnie przygotowane scenariusze, np.:
- latwe 3:0,
- standardowe 3:0,
- standardowe 3:1,
- trudne 3:2.

### 2. Jeden scenariusz reczny

Przyklad:
- `25:10, 25:9, 25:10`

### 3. Kilka scenariuszy recznych

Przyklad:
- `25:20, 25:18, 25:22`
- `25:22, 22:25, 25:21, 25:19`
- `25:21, 20:25, 23:25, 25:20, 15:12`

## Reprezentacja planu zmian

Plan powinien byc prezentowany w formie:
- pozycja,
- starter,
- zmiennik,
- set,
- prog aktywacji zmiany,
- opis gotowy do przepisania do gry.

Przyklad:
- `Srodkowy: Kowalski startuje, Set 2 od 1 punktu -> Lis`

## Sposob oceny wariantow

Priorytety:
1. Maksymalizacja sumy koncowych paskow zawodnikow bioracych udzial.
2. Minimalizacja zmarnowanych akcji ponad limit mozliwego przyrostu.
3. Przy remisie preferowanie prostszego planu zmian.

## Plan implementacji

### Etap 1. Setup projektu

- skonfigurowac Laravel Sail,
- uruchomic MySQL,
- przygotowac podstawowy layout aplikacji,
- ustawic routing i strone startowa.

### Etap 2. Zawodnicy

- migracja `players`,
- model `Player`,
- seed pozycji lub enum pozycji,
- Livewire component do listy i edycji zawodnikow,
- walidacja danych.

### Etap 3. Formularz optymalizacji

- Livewire component formularza,
- wybor 2 pozycji,
- wybor trybu scenariusza,
- formularz wynikow setow,
- walidacja wejscia.

### Etap 4. Silnik obliczeniowy

- klasy DTO dla scenariuszy,
- kalkulator paska,
- generator wariantow zmian,
- serwis oceny wariantow,
- ranking top wynikow.

### Etap 5. Prezentacja wyniku

- widok rekomendowanego skladu,
- widok planu zmian,
- tabela efektow koncowych,
- szczegoly dla top wariantow.

### Etap 6. Dopracowanie UX

- szybka edycja paskow,
- filtry i sortowanie zawodnikow,
- zapis presetow scenariuszy,
- dopracowanie czytelnosci planu zmian.

## Ryzyka i punkty do doprecyzowania

- Trzeba precyzyjnie ustalic interpretacje progu zmiany w secie.
- Trzeba ustalic, czy dla wielu scenariuszy szukamy jednego wspolnego planu, czy tylko pokazujemy optimum dla kazdego osobno.
- Trzeba ustalic granularnosc przeszukiwania progow zmiany, np. co 1 punkt czy co kilka punktow.
- Trzeba ustalic, czy system ma brac pod uwage tylko aktywnych zawodnikow, czy rowniez czasowo ukrytych.

## Rekomendacja na start

Pierwsza wersja powinna:
- pozwolic zarzadzac lista zawodnikow,
- umiec policzyc wynik dla jednego recznego scenariusza,
- wygenerowac top 5 wariantow,
- pokazac gotowy plan zmian do przepisania do VM-Managera.

Po potwierdzeniu dzialania MVP mozna dodac:
- pakiety scenariuszy z wagami,
- historie analiz,
- bardziej zaawansowane modelowanie momentu wejscia podczas seta.
