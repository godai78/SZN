# SZN (System Zarządzania Naborem)
> Prosty, lekki i bezpieczny system w jednym pliku PHP do obsługi naboru tekstów (np. do antologii lub czasopism).

## Główne funkcje
- **Samodzielne wgrywanie plików przez osoby biorące udział w naborze**
- **Obsługa formatów DOC, DOCX oraz RTF** (z wbudowaną walidacją zawartości – *magic bytes*).
- **Automatyczne generowanie linków:** Autor/ka po przesłaniu zgłoszenia od razu otrzymuje unikalny, bezpieczny link do śledzenia statusu tekstu.
- **Panel redaktora:** Ochrona hasłem, zmiana statusów (*otrzymane, w czytaniu, czeka na decyzję, przyjęte, odrzucone, wycofane*), bezpieczne pobieranie plików.
- **Bezpieczeństwo:** Ochrona przed CSRF, ukryty katalog na pliki zablokowany przez `.htaccess`, limity rozmiaru (domyślnie do 5 MB).
- **Brak bazy danych:** Całość oparta na jednym pliku PHP i zabezpieczonym pliku JSON (`data.php`).

---

## Instalacja

1. Pobierz plik `index.php` i umieść go na serwerze obsługującym PHP.
2. Otwórz plik w edytorze i skonfiguruj stałe na początku kodu:
   - `PASSWORD` – hasło dostępu do panelu redaktora.
   - `SITE_NAME` – nazwa naboru wyświetlana na stronie.
3. Utwórz na serwerze zapisywalny katalog na pliki (skrypt utworzy go automatycznie wraz z zabezpieczeniem `.htaccess`, jeśli ma odpowiednie uprawnienia).

---

## Obsługa

### Autor/ka (zgłaszający/a):
1. Wchodzi na stronę główną naboru.
2. Wpisuje swoje dane (imię/nazwisko/pseudonim), tytuł utworu i wgrywa plik tekstowy (DOC, DOCX, RTF).
3. Po wysłaniu otrzymuje **unikalny link** do śledzenia statusu zgłoszenia (system nie wysyła maili – autor musi zapisać link).
4. Pod linkiem widzi aktualny stan tekstu, czas oczekiwania oraz liczbę tekstów w kolejce.

### Redaktor/ka:
1. Wchodzi na tę samą stronę i na dole rozwija sekcję **Panel redakcji**.
2. Wpisuje hasło, aby uzyskać dostęp do listy zgłoszeń.
3. Może zmieniać statusy tekstów za pomocą listy rozwijanej lub pobierać bezpiecznie pliki na dysk.


Bartek Biedrzycki (c) 2026, na potrzeby magazynu Rocznik Fantastyczny.

Licencja MIT.
