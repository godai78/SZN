# SZN – System Zarządzania Naborem

Prosty system do przyjmowania i obsługi zgłoszeń literackich. Umożliwia autorom przesyłanie opowiadań oraz sprawdzanie statusu zgłoszenia, a redakcji – przeglądanie tekstów i zarządzanie naborem.

## Główne funkcje

- Formularz zgłoszeniowy dla autorów.
- Przyjmowanie plików DOC, DOCX i RTF o wielkości do 5 MB.
- Automatyczne nadawanie zgłoszeniom indywidualnych linków do śledzenia statusu.
- Panel redakcyjny zabezpieczony hasłem.
- Zmiana statusów zgłoszeń:
  - otrzymane,
  - w czytaniu,
  - czeka na decyzję,
  - przyjęte,
  - odrzucone,
  - wycofane.
- Pobieranie nadesłanych plików z panelu redakcyjnego.
- Zapisywanie danych w pliku, bez konieczności instalowania bazy danych.
- Ograniczenia liczby zgłoszeń i prób logowania.
- Automatyczne wylogowanie redaktora po 30 minutach bezczynności.

## Wymagania

- Serwer obsługujący PHP 7.4 lub nowszy.
- Włączone rozszerzenia PHP: `mbstring` i `ZipArchive`.
- Obsługa sesji PHP.
- Serwer pozwalający na blokowanie plików (`flock`) i atomową zamianę plików (`rename`).
- Certyfikat SSL – system powinien działać przez HTTPS.

## Instalacja

1. Skopiuj plik `index.php` na serwer.
2. Przygotuj hash hasła redakcyjnego za pomocą narzędzia opartego na funkcji PHP `password_hash()`. Do wygenerowania hasha możesz użyć np. https://codeshack.io/php-password-hash-generator/
3. Otwórz plik i uzupełnij sekcję konfiguracji na początku:

   - `PASSWORD_HASH` – hash hasła redakcyjnego. Do konfiguracji wklej wygenerowany ciąg znaków, a nie samo hasło! 
   - `SITE_NAME` – nazwa pisma lub naboru.
   - `BASE_URL` – pełny adres HTTPS prowadzący do skryptu, np. `https://twojadomena.pl/index.php`.

4. Upewnij się, że katalog prywatny aplikacji może być tworzony i zapisywany przez PHP. Można zmienić ten katalog w konfiguracji w pliku.
5. Sprawdź, czy PHP może zapisywać pliki w katalogu aplikacji – znajduje się tam plik `data.php`.
6. Otwórz adres formularza w przeglądarce i wykonaj próbne zgłoszenie.


Oprogramowanie dostarczane jest w trybie "as is", a autor nie ponosi odpowiedzialności za utracone dane.

