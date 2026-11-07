#Changelog

## 1.0 (2026-10-14)
Wersja z samymi linkami
* Pozwala prowadzącym nabór na tworzenie bazy zgłoszeń i generowanie linków do śledzenie, które można wysłać autorom.
* Dodaje zgłoszenie do bazy z możliwości zmian statusów przez administratora.

## 2.0 beta (2026-11-04)
* Wersja z uploaderem
  * Pozwala zgłaszającym wgrać plik do systemu.
  * Automatycznie tworzy link śledzenia i wyświetla zgłaszającym.
  * Zapisuje plik na serwerze.
  * Dodaje plik do bazy z możliwością zmian statusów przez administratora oraz pobrania.

## 2.1 (2026-11-9)
* Rozszerzone zabezpieczenia kryptograficzne i mechanizmy obronne
  * Wprowadzono obsługę hashowania haseł (password_verify) oraz wsparcie dla zmiennej środowiskowej EDITOR_PASSWORD_HASH.
  * Dodano mechanizmy rate-limitingu (ograniczenia liczby prób) chroniące przed atakami typu Brute Force dla logowania redaktora oraz przed masowym spamowaniem zgłoszeniami z jednego adresu IP.
  * Wdrożono automatyczny wylogowywanie redaktora po okresie bezczynności (EDITOR_IDLE_TIMEOUT – domyślnie 30 minut).

* Bezpieczeństwo operacyjne i spójność danych
  * Zaimplementowano bezpieczne blokowanie plików przy zapisie (flock) oraz atomową podmianę danych przez pliki tymczasowe, co zapobiega uszkodzeniom bazy JSON przy jednoczesnych zgłoszeniach.
  * Wzmocniono rygorystyczną walidację nagłówków binarnych (magic bytes) oraz struktury wewnętrznej dla wgrywanych plików (DOC, DOCX, RTF).
  * Poprawiono kodowanie oraz nagłówki pobierania plików (Content-Disposition), zapewniając poprawne nazwy oryginalnych plików bez ryzyka błędów i podatności.
