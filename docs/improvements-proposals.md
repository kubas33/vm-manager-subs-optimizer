# Propozycje dalszych ulepszeń optymalizatora

Ten dokument przechowuje pomysły na kolejne usprawnienia aplikacji. Automatyczna korekta planu oraz prezentowanie strat treningowych są już zaimplementowane i nie powtarzają się na poniższej liście.

## 1. Ręczna korekta planu z natychmiastowym przeliczeniem

Użytkownik powinien móc zmienić zawodnika aktywnego w konkretnym secie i od razu zobaczyć wpływ tej decyzji na plan.

Po zmianie aplikacja powinna:

- przeliczyć zysk treningowy, straty i końcowe paski;
- sprawdzić legalność składu, wspólnej ławki oraz zmian VM;
- pokazać różnicę względem poprzedniego planu, na przykład `+11 treningu` i `-11 stratnych akcji`;
- zablokować zapis planu, jeśli zawodnik występuje jednocześnie na dwóch slotach albo przekroczony zostaje limit ławki.

Najlepiej zacząć od osobnego trybu edycji wybranego wariantu, bez zmieniania rekomendacji generowanej automatycznie.

## 2. Wymuszanie i wykluczanie zawodników

Przed uruchomieniem optymalizacji użytkownik powinien móc określić ograniczenia:

- zawodnik musi być starterem na danej pozycji;
- zawodnik musi pojawić się w konkretnym secie;
- zawodnik nie może być użyty w planie;
- zawodnik może być użyty wyłącznie jako rezerwowy.

Ograniczenia powinny wejść do generatora jako część danych wejściowych, a nie być nakładane na gotowy wariant. Dzięki temu ranking nadal będzie poprawny, a każdy wygenerowany wariant będzie spełniał te same zasady.

## 3. Lepsze porównanie wariantów

Karty wariantów powinny pokazywać nie tylko numer i łączny zysk, ale także:

- różnicę w zysku względem rekomendacji;
- różnicę w liczbie stratnych akcji;
- liczbę zmian i reguł VM;
- liczbę zawodników, którzy osiągają swój limit treningowy;
- informację, że dwa warianty mają taki sam zysk, ale jeden wymaga mniejszej liczby zmian.

Przydatny byłby także prosty tryb „porównaj”, w którym użytkownik wybiera dwa warianty i widzi różniące się sloty oraz sety.

## 4. Wyjaśnienie rekomendacji

Przy rekomendowanym wariancie warto pokazać krótkie uzasadnienie w języku użytkownika, na przykład:

> Kwiatek i Wasyliszyn dostają pełne wykorzystanie limitu treningowego. Król odpoczywa w trzecim secie, ponieważ jego limit został wcześniej osiągnięty.

Uzasadnienie powinno korzystać z danych diagnostycznych już wyliczanych przez optymalizator i wskazywać najważniejsze decyzje, a nie opisywać algorytm.

## 5. Historia i zapis własnych wariantów

Użytkownik powinien móc zapisać wybrany wariant pod własną nazwą, na przykład `Rotacja na trudny mecz`, a następnie:

- wrócić do niego po ponownym przeliczeniu;
- skopiować go jako punkt wyjścia do ręcznej korekty;
- porównać go z aktualną rekomendacją;
- oznaczyć go jako wysłany do VM Managera.

W pierwszej wersji wystarczyłby zapis w sesji lub lokalnej historii. Trwały zapis w bazie można dodać później.

## Sugerowana kolejność

1. Ręczna korekta planu.
2. Wymuszanie i wykluczanie zawodników.
3. Lepsze porównanie wariantów.
4. Wyjaśnienie rekomendacji.
5. Historia własnych wariantów.

Pierwsze trzy propozycje bezpośrednio zwiększają kontrolę użytkownika nad planem. Dwie ostatnie poprawiają zrozumienie i późniejsze korzystanie z gotowych rekomendacji.
