# VM Manager Subs Optimizer - Tasks

## Jak czytac ten plik

- `PLAN.md` zostaje dokumentem produktu i zalozen.
- Ten plik jest backlogiem implementacyjnym MVP.
- Statusy:
  - `[ ]` do zrobienia
  - `[-]` w toku
  - `[x]` zrobione

## Priorytet teraz

1. Domknac bazowy szkielet domeny i UI.
2. Postawic pierwszy przeplyw: zawodnicy -> formularz optymalizacji -> pusty wynik.
3. Dopiero potem wejsc w silnik obliczeniowy i ranking wariantow.

## Etap 0. Srodowisko i runtime

- [x] Skonfigurowac Laravel Sail z MariaDB.
  - Done when: `sail up -d` uruchamia aplikacje i baze.
- [x] Ustawic polaczenie aplikacji na MariaDB w `.env`.
  - Done when: `DB_CONNECTION=mariadb` i serwis bazy odpowiada.
- [x] Uruchomic podstawowe migracje projektu.
  - Done when: `sail artisan migrate` przechodzi bez bledu.
- [ ] Ustawic frontend runtime dla developmentu.
  - Zakres: `sail npm install`, `sail npm run dev`.
  - Done when: Vite buduje assety i strona laduje sie bez bledu.

## Etap 1. Fundament domeny players

- [x] Dodac enum pozycji zawodnika.
  - Zakres: lista pozycji wspieranych przez MVP, etykiety do UI, helpery do selectow.
  - Done when: pozycje sa trzymane centralnie i nie ma hardcoded stringow po widokach.
  - Zaleznosci: Etap 0.
- [x] Dodac migracje `players`.
  - Zakres: `name`, `position`, `training_bar`, `active`, timestamps.
  - Done when: migracja przechodzi, `training_bar` ma zakres 0-100 na poziomie walidacji aplikacji.
  - Zaleznosci: enum pozycji.
- [x] Dodac model `Player`.
  - Zakres: fillable, casty, scope `active`, helpery domenowe pod pasek treningowy.
  - Done when: model nadaje sie do formularzy, listy i obliczen.
  - Zaleznosci: migracja `players`.
- [x] Dodac factory `PlayerFactory`.
  - Zakres: sensowne fake dane, rozne pozycje, aktywnosc i losowy pasek.
  - Done when: testy moga tworzyc zestawy zawodnikow bez recznego setupu.
  - Zaleznosci: model `Player`.
- [x] Dodac seed bazowych danych developerskich.
  - Zakres: mala pula zawodnikow dla kazdej pozycji.
  - Done when: po seedzie da sie wejsc do UI i od razu cos klikac.
  - Zaleznosci: factory `PlayerFactory`.

## Etap 2. Szkielet aplikacji i routing

- [x] Przebudowac dashboard pod realny kontekst aplikacji.
  - Zakres: podsumowanie liczby aktywnych zawodnikow, szybkie linki i status gotowosci danych.
  - Done when: dashboard nie jest placeholderem starter-kita.
  - Zaleznosci: model `Player`.
- [x] Dodac trasy aplikacyjne MVP.
  - Zakres: `dashboard`, `players.index`, `optimizer.create`, `optimizer.result`.
  - Done when: kazdy ekran ma route name i dziala pod `Route::livewire()`.
  - Zaleznosci: Etap 0.
- [x] Zmienic nawigacje w sidebarze i branding.
  - Zakres: nazwa aplikacji, linki do ekranow MVP, usuniecie linkow starter-kita.
  - Done when: sidebar prowadzi po realnych ekranach projektu.
  - Zaleznosci: trasy aplikacyjne MVP.

## Etap 3. UI zarzadzania zawodnikami

- [x] Dodac pelnostronicowy komponent Livewire dla listy zawodnikow.
  - Zakres: tabela lub lista, podstawowe statystyki, filtrowanie po pozycji i aktywnosci.
  - Done when: uzytkownik widzi wszystkich zawodnikow i moze ich filtrowac.
  - Zaleznosci: model `Player`.
- [x] Dodac formularz tworzenia zawodnika.
  - Zakres: imie i nazwisko, pozycja, pasek treningowy, aktywny.
  - Done when: nowy zawodnik zapisuje sie z walidacja i feedbackiem UI.
  - Zaleznosci: komponent listy zawodnikow.
- [x] Dodac edycje zawodnika w miejscu lub w panelu bocznym.
  - Zakres: szybka zmiana nazwy, pozycji, paska i statusu aktywnosci.
  - Done when: da sie zaktualizowac zawodnika bez opuszczania ekranu.
  - Zaleznosci: formularz tworzenia zawodnika.
- [x] Dodac walidacje formularzy players.
  - Zakres: unikalnosc i format nazwy zgodnie z decyzja produktowa, pozycja z enum, zakres `training_bar`.
  - Done when: bledne dane nie trafiaja do bazy i sa pokazane w UI.
  - Zaleznosci: formularze players.

## Etap 4. Formularz optymalizacji

- [ ] Dodac komponent Livewire dla formularza optymalizacji.
  - Zakres: wybor dwoch pozycji, tryb scenariusza, pole na dane meczowe, submit.
  - Done when: formularz renderuje sie poprawnie i zapisuje stan wejscia.
  - Zaleznosci: enum pozycji, routing MVP.
- [ ] Dodac tryby scenariusza.
  - Zakres: preset, jeden scenariusz reczny, kilka scenariuszy recznych.
  - Done when: formularz dynamicznie pokazuje odpowiednie pola dla wybranego trybu.
  - Zaleznosci: komponent formularza.
- [ ] Dodac walidacje scenariuszy.
  - Zakres: poprawny format setow, liczba setow 3-5, brak pustych rekordow.
  - Done when: wejscie jest gotowe do mapowania na DTO.
  - Zaleznosci: tryby scenariusza.
- [ ] Dodac mapowanie wejscia na obiekty domenowe.
  - Zakres: parser scenariuszy, DTO wejscia, normalizacja danych.
  - Done when: silnik obliczeniowy dostaje juz ustrukturyzowane dane.
  - Zaleznosci: walidacja scenariuszy.

## Etap 5. Silnik obliczeniowy MVP

- [ ] Dodac DTO `MatchScenario`.
  - Zakres: sety, wynik, liczba akcji i pomocnicze metody.
  - Done when: pojedynczy scenariusz ma jedna reprezentacje w domenie.
  - Zaleznosci: mapowanie wejscia.
- [ ] Dodac DTO `ScenarioSet`.
  - Zakres: kolekcja scenariuszy, opcjonalne wagi, agregacja wyniku.
  - Done when: mozna liczyc jeden lub wiele scenariuszy jednym interfejsem.
  - Zaleznosci: `MatchScenario`.
- [ ] Dodac `TrainingGainCalculator`.
  - Zakres: limit 50 na mecz, limit 100 calosci, obliczanie zmarnowanych akcji.
  - Done when: kalkulator ma testy jednostkowe dla granicznych przypadkow.
  - Zaleznosci: model `Player`, DTO scenariusza.
- [ ] Dodac `SubstitutionPlanGenerator`.
  - Zakres: generowanie dozwolonych wariantow dla dwoch slotow i jednej pozycji na slot.
  - Done when: generator zwraca tylko legalne kombinacje.
  - Zaleznosci: `MatchScenario`, enum pozycji.
- [ ] Dodac `TrainingOptimizerService`.
  - Zakres: ocena wariantow, scoring, sortowanie i ograniczenie do top N.
  - Done when: dla danego wejscia zwracany jest ranking wariantow z punktacja.
  - Zaleznosci: kalkulator i generator planow.
- [ ] Dodac konfiguracje pelnej lawki rezerwowych dla analizowanego skladu.
  - Zakres: uwzglednienie skladu meczowego jako 2 zawodnikow w pierwszym skladzie dla analizowanych slotow + do 5 rezerwowych lacznie, bez sztywnego limitu na pozycje.
  - Done when: algorytm potrafi analizowac pelna lawke, takze w scenariuszu typu 2 srodkowych w pierwszym skladzie + 5 kolejnych srodkowych jako rezerwowi.
  - Zaleznosci: `SubstitutionPlanGenerator`, `TrainingOptimizerService`.
- [ ] Dodac scoring uwzgledniajacy pewnosc setow.
  - Zakres: preferowanie planow, ktore maksymalizuja przyrost w pierwszych 3 setach jako czesci pewnej, a sety 4-5 traktuja jako bonus / mniejsza wage.
  - Done when: dla scenariuszy 3:1 i 3:2 algorytm premiuje wczesniejsze wejscia zawodnikow, jesli zwieksza to pewny zysk z pierwszych 3 setow.
  - Zaleznosci: `TrainingOptimizerService`.

## Etap 6. Prezentacja wyniku

- [ ] Dodac ekran wyniku optymalizacji.
  - Zakres: starterzy, zmiennicy, plan zmian, podsumowanie efektu.
  - Done when: uzytkownik widzi najlepszy wariant i top liste.
  - Zaleznosci: `TrainingOptimizerService`.
- [ ] Dodac czytelny format planu zmian.
  - Zakres: opis gotowy do przepisania do gry, per slot i per set.
  - Done when: wynik jest zrozumialy bez interpretowania surowych danych.
  - Zaleznosci: ekran wyniku.
- [ ] Dodac tabele efektow koncowych.
  - Zakres: pasek startowy, przyrost, pasek koncowy, zmarnowane akcje.
  - Done when: da sie porownac efekt dla wszystkich zawodnikow z wariantu.
  - Zaleznosci: ekran wyniku.

## Etap 7. Testy

- [ ] Dodac testy feature dla tras MVP.
  - Zakres: dashboard, players, optimizer, result.
  - Done when: zalogowany uzytkownik moze otworzyc kazdy ekran, a gosc jest blokowany tam gdzie trzeba.
  - Zaleznosci: routing MVP.
- [ ] Dodac testy feature dla CRUD players.
  - Zakres: tworzenie, edycja, filtrowanie, walidacja.
  - Done when: glowny przeplyw zarzadzania zawodnikami jest zabezpieczony testami.
  - Zaleznosci: UI players.
- [ ] Dodac testy jednostkowe dla obliczen.
  - Zakres: limity paska, scenariusze 3:0 / 3:1 / 3:2, tie-break, zmarnowane akcje.
  - Done when: najwazniejsze reguly domenowe maja pokrycie testowe.
  - Zaleznosci: silnik obliczeniowy MVP.

## Etap 8. Dopracowanie po MVP

- [ ] Dodac presety scenariuszy zapisane w aplikacji.
- [ ] Dodac obsluge wielu scenariuszy z wagami.
- [ ] Dodac historie uruchomien optymalizacji.
- [ ] Dodac szybsza edycje paskow z listy zawodnikow.
- [ ] Dopracowac heurystyki progow zmian i uproszczenie planu.
- [ ] Dopracowac heurystyke dla meczow dluzszych niz 3 sety.
  - Zakres: pierwsze 3 sety traktowac jako czesc gwarantowana, a 4 i 5 set jako czesc niepewna.
  - Done when: plan zmian nie odklada kluczowych wejsc tylko na set 4-5, jesli ten sam zawodnik moglby pewniej zyskac pasek juz w 1-3 secie.
- [ ] Dodac UI sterowania liczba rezerwowych branych do optymalizacji dla pozycji.
  - Zakres: pole lub selektor przy formularzu optymalizacji, ktory okresla, ilu rezerwowych lacznie ma trafic do analizowanej lawki, bez narzucania limitu per pozycja.
  - Done when: user moze zdecydowac, czy optymalizacja ma uwzgledniac pelna lawke 5 rezerwowych, czy waska pule kandydatow dla szybszych obliczen.

## Otwarte decyzje domenowe

- [ ] Ustalic, czym dokladnie jest `prog aktywacji zmiany` w secie.
- [ ] Ustalic, czy dla wielu scenariuszy liczymy jeden wspolny plan, czy osobne optima.
- [ ] Ustalic granularnosc przeszukiwania progow zmian.
- [ ] Ustalic finalna liste obslugiwanych pozycji w MVP.
- [ ] Ustalic, czy user ma recznie wybierac liczbe rezerwowych lacznie, czy algorytm ma zawsze zakladac pelna lawke 5 zawodnikow.
- [ ] Ustalic, czy i kiedy algorytm moze technicznie zawezac pelna lawke do mniejszej puli kandydatow bez gubienia sensownych wariantow.
- [ ] Ustalic, jaka waga ma byc przypisana setom 4-5 wzgledem pierwszych 3 setow w scenariuszach niepewnych.

## Najblizszy implementacyjny slice

- [x] Dodac enum pozycji.
- [x] Dodac migracje, model, factory i seed `Player`.
- [x] Podmienic dashboard i sidebar na wersje projektowa.
- [x] Dodac ekran `players.index` z lista i formularzem dodawania.
- [x] Dodac testy feature dla dashboardu i ekranu zawodnikow.
