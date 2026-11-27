# Reguły Kodowania i Wydajności: PHP 8.6.0+

> **Cel nadrzędny:** Zawsze używaj wyłącznie najnowszych składni, API oraz optymalizacji silnika wprowadzonych w **PHP 8.6.0** (oraz nowoczesnych fundamentów z PHP 8.4/8.5). Unikaj przestarzałych wzorców (legacy), zbędnych wrapperów i narzutu wywołań w przestrzeni użytkownika (userland).

---

## 1. Pliki i Ścisłe Typowanie (Zero Narzutu Typów)

- Zawsze zaczynaj każdy plik PHP od `declare(strict_types=1);` — zapobiega to kosztownej niejawnej konwersji typów (type juggling) w czasie wykonania i pozwala JIT generować wyspecjalizowany kod maszynowy.
- Jawnie typuj wszystkie właściwości klas, parametry metod, typy zwracane (`void`, `never`, `mixed`, typy złożone DNF) oraz stałe klasowe (`public const string VERSION = '1.0.0';`).
- Stosuj atrybut `#[\Override]` nie tylko na metodach, ale również na **stałych klasowych i przypadkach enum** nadpisujących definicje z klas bazowych lub interfejsów (nowość PHP 8.6 — weryfikacja w czasie kompilacji).

---

## 2. Funkcje Wyższego Rzędu, PFA i Optymalizacja Kompilatora (PHP 8.6)

- **Partial Function Application (PFA):** Zamiast tworzyć anonimowe funkcje strzałkowe (`fn($x) => ...`) tylko po to, aby przekazać stałe argumenty, używaj natywnego częściowego aplikowania funkcji z placeholderami `?` (pojedynczy argument) oraz `...` (reszta argumentów):
  ```php
  // ZALECANE (PHP 8.6 — mniejszy narzut domknięcia):
  $slugify = str_replace(' ', '-', ?);
  $onlyNumeric = array_filter(?, is_numeric(...));

  // ZAKAZANE (zbędny wrapper closure):
  $slugify = static fn(string $s): string => str_replace(' ', '-', $s);
  ```
- **Optymalizacja `array_map()` w kompilatorze PHP 8.6:** W wywołaniach `array_map()` preferuj **First-Class Callables** (`foo(...)`) lub **PFA** (`foo('bar', ?)`). Silnik PHP 8.6 automatycznie kompiluje takie wywołania bezpośrednio do niskopoziomowej pętli `foreach` w VM, całkowicie eliminując narzut wywołania funkcji zwrotnej (~10% wyższa wydajność).
- **Bezstanowe domknięcia (`static fn` / `static function`):** Jeśli musisz użyć domknięcia (closure) i nie korzystasz z `$this`, zawsze deklaruj je jako `static`. PHP 8.6 agresywnie cache'uje bezstanowe domknięcia i unika wiązania kontekstu obiektu (`$this`).
- **Pipe Operator (`|>` z PHP 8.5 + PFA z PHP 8.6):** Łącz transformacje danych potokowo zamiast tworzyć zmienne tymczasowe lub zagnieżdżać wywołania:
  ```php
  $normalized = $input
      |> trim(...)
      |> str_replace(' ', '-', ?)
      |> strtolower(...);
  ```

---

## 3. Natywne API Standardowe PHP 8.6 zamiast Kodu Userland

- **Ograniczanie wartości (`clamp()`):** Nigdy nie używaj `max($min, min($val, $max))` ani instrukcji `if/else` do ograniczania zakresu. Zawsze używaj natywnej funkcji C **`clamp($value, $min, $max)`** (~12.5% szybsza, czytelniejsza, obsługuje liczby i obiekty porównywalne, w tym `\Time\Duration`).
- **Czas trwania i benchmarki (`\Time\Duration`):** Do reprezentowania upływu czasu, timeoutów, opóźnień retry, TTL i pomiarów wydajności używaj natywnej klasy **`\Time\Duration`** (precyzja nanosekundowa, niezależna od stref czasowych i kalendarza) zamiast surowych `int`/`float` z mikrosekundami lub ciężkich obiektów `DateInterval`/`DateTimeImmutable`.
- **Kierunek sortowania (`\SortDirection`):** Zamiast własnych stringów (`'ASC'`, `'DESC'`), stałych lub lokalnych enumów w zapytaniach, repozytoriach i kolekcjach używaj wbudowanego, zunifikowanego enuma **`\SortDirection::Ascending`** oraz **`\SortDirection::Descending`**.
- **Skalowalne I/O i multipleksowanie (`\Io\Poll`):** Przy operacjach asynchronicznych, serwerach socketowych i monitorowaniu wielu strumieni używaj nowego natywnego API **`\Io\Poll`** (opartego o `epoll` / `kqueue` / `WSAPoll`) zamiast przestarzałego i limitowanego `stream_select()`.
- **Operacje tablicowe i URI (PHP 8.4/8.5+):**
  - Zamiast `reset()` / `end()` lub `$arr[array_key_first($arr)]` stosuj `array_first($array)` i `array_last($array)`.
  - Zamiast pętli szukających elementu używaj natywnych funkcji w C: `array_find()`, `array_find_key()`, `array_any()`, `array_all()`.
  - Do parsowania adresów URL używaj natywnego, zgodnego z RFC 3986/WHATWG rozszerzenia `Uri\Rfc3986\Uri` / `Uri\WhatWg\Url` zamiast przestarzałego `parse_url()`.

---

## 4. Klasy, Niemutowalność i Pamięć

- **Domyślne wartości dla `readonly` (PHP 8.6):** Właściwości `readonly` (również w klasach `readonly class`) inicjalizuj domyślnymi wartościami bezpośrednio w deklaracji właściwości, gdy wartość jest znana statycznie, zamiast wymuszać przypisanie w konstruktorze:
  ```php
  final readonly class ApiConfig
  {
      public function __construct(
          public string $endpoint,
          public int $maxRetries = 3,
      ) {}

      public \SortDirection $defaultOrder = \SortDirection::Ascending;
  }
  ```
- **Używaj `final` i `readonly` domyślnie:** Oznaczaj klasy jako `final` (lub `final readonly`), o ile nie są celowo zaprojektowane do dziedziczenia. Pozwala to OPcache i JIT na de-wirtualizację wywołań metod i optymalizację układu pamięci obiektu.
- **Property Hooks i Asymmetric Visibility (PHP 8.4+):** Zamiast boilerplate'u getterów/setterów używaj asymetrycznej widoczności (`public private(set) string $status`) oraz lekkich hooków właściwości (`get` / `set`). Klony niemutowalnych obiektów modyfikuj przez `clone($object, ['prop' => $newValue])` (Clone With z PHP 8.5).

---

## 5. Obsługa Błędów, Strumieni i Usunięte Konstrukcje (PHP 8.6)

- **Ścisła kontrola błędów I/O i strumieni:** W PHP 8.6 funkcje `fclose()`, `file_put_contents()` oraz `copy()` rygorystycznie zgłaszają błędy opróżniania bufora (flush/close) i rzucają ustrukturyzowane wyjątki/zwracają `false`. Zawsze weryfikuj wynik zapisu lub obsługuj wyjątki strumieni.
- **Zakazane konstrukcje (Deprecated w PHP 8.6):**
  - **Nigdy** nie używaj `return` wewnątrz bloku `finally` (tłumi aktywne wyjątki i jest zdeprecjonowane w PHP 8.6).
  - **Nigdy** nie zwracaj wartości z `__construct()` ani `__destruct()`.
  - Nie polegaj na ręcznym osłabianiu ustawień sesji — PHP 8.6 domyślnie wymusza `session.use_strict_mode=1`, `session.cookie_httponly=1` oraz `session.cookie_samesite=Lax`.

---

## 6. Maksymalna Wydajność Silnika (Runtime, ZTS, OPcache & JIT)

- **Gotowość na środowiska wielowątkowe (ZTS):** PHP 8.6 zredukowało narzut Zend Thread Safety (ZTS) o ~50%. Pisz kod w 100% bezpieczny wątkowo i bezstanowy między żądaniami (unikaj mutowalnego stanu globalnego i statycznych cache'y rosnących bez limitu), aby maksymalnie wykorzystać workery **FrankenPHP**, Apache MPM Event czy wielowątkowe środowiska Windows.
- **Wewnętrzne wywołania i root namespace:** W krytycznych ścieżkach wydajnościowych importuj funkcje i klasy przez `use function` / `use` lub poprzedzaj wywołania natywne znakiem `\`,
- **Przedrostek `\` przed natywnymi funkcjami PHP (root namespace):** W plikach z przestrzenią nazw (`namespace`) zawsze poprzedzaj wywołania natywnych funkcji PHP znakiem `\` (np. `\strlen()`, `\count()`, `\clamp()`, `\in_array()`, `\is_int()`, `\implode()`, `\realpath()`), o ile dana funkcja to wspiera (nie stosuj `\` przed konstrukcjami językowymi takimi jak `isset`, `empty`, `unset`, `echo`, `print`, `require`, `require_once`, `include`, `include_once`, `clone`, `list`). Pozwala to kompilatorowi emitować bezpośrednie instrukcje OPcode bez narzutu przeszukiwania przestrzeni nazw.
- **Struktury danych i alokacje:**
  - Zwracaj `Generator` (`yield`) przy przetwarzaniu dużych zbiorów danych i strumieni.
  - Używaj packed arrays (list z ciągłymi kluczami całkowitymi od `0`) — zajmują znacznie mniej pamięci w Zend Engine niż tablice asocjacyjne.
  - Unikaj dynamicznych właściwości (`#[AllowDynamicProperties]` jest zakazane), referencji `&` (degradują copy-on-write i analizę JIT) oraz operatora tłumienia błędów `@`.

---

## 7. Architektura Bezstanowa (Stateless), Mikrousługi i Swoole

Cały kod projektu (zarówno rdzeń, jak i warstwa aplikacyjna / TSR) **musi być w 100% bezstanowy (stateless)**, gotowy na horyzontalne skalowanie w architekturze mikrousług oraz długo żyjące workery i korutyny **Swoole** / OpenSwoole / FrankenPHP:

- **Zero stanu współdzielonego w procesie (Swoole & Coroutine Safety):**
  - **Zakaz mutowalnych właściwości statycznych (`static $prop`) i singletonów trzymających stan żądania** — w Swoole jeden proces workera obsługuje tysiące żądań współbieżnie w korutynach; stan zapisany w klasie lub serwisie wycieknie między użytkownikami (data leak / race condition) i spowoduje wycieki pamięci (memory leaks).
  - Wszystkie serwisy w kontenerze DI muszą być bezstanowe (`final readonly`), a dane specyficzne dla żądania przekazuj wyłącznie jawnie przez obiekty DTO / `Request` / niemutowalny `Context` (lub izolowany kontekst korutyny).
- **Zakaz superglobali i funkcji blokujących cykl życia PHP-FPM:**
  - Nigdy nie odczytuj ani nie modyfikuj bezpośrednio `$_GET`, `$_POST`, `$_SESSION`, `$_COOKIE`, `$_SERVER`, `$_FILES` — operuj wyłącznie na izolowanych obiektach żądania/odpowiedzi (PSR-7 / Request DTO).
  - **Zakaz używania `exit`, `die`, `header()`, `setcookie()`, `session_start()` oraz `echo` poza warstwą transportera HTTP** — w Swoole `exit`/`die` zabija cały proces workera, a natywne funkcje nagłówków nie działają poza SAPI FPM/CLI-Server.
- **Gotowość na Mikrousługi i Skalowanie Horyzontalne (12-Factor App):**
  - **Brak stanu lokalnego:** Sesje, cache, blokady rozproszone (distributed locks), rate-limiting i kolejki muszą korzystać z zewnętrznych, współdzielonych magazynów (np. Redis, baza danych, broker wiadomości), nigdy z pamięci RAM pojedynczej instancji czy lokalnego systemu plików (`/tmp` / `storage` dla danych ulotnych między żądaniami).
  - **Nieblokujące I/O i korutyny:** Operacje sieciowe, bazodanowe (connection pooling zamiast łączenia per-request) i strumieniowe projektuj tak, aby nie blokowały pętli zdarzeń (Event Loop) w Swoole oraz wykorzystywały natywne `\Io\Poll` i `\Time\Duration` do ścisłych timeoutów komunikacji między mikrousługami.
  - Kontrakty między modułami i usługami opieraj na lekko serializowalnych, niemutowalnych obiektach `final readonly` DTO (JSON / Protobuf / MessagePack).

---

## 8. Bezpieczeństwo Kodu i Zero Wycieków Pamięci (Memory Safety & Security)

- **Brak wycieków pamięci w długo żyjących procesach (Zero Memory Leaks):**
  - **Zakaz nieograniczonych buforów i tablic w serwisach:** Nigdy nie akumuluj obiektów, logów, eventów ani wyników zapytań w polach długo żyjących obiektów lub zmiennych statycznych. Jeśli lokalna mapa powiązań obiektów jest niezbędna, używaj wyłącznie **`\WeakMap`** oraz **`\WeakReference`** (pozwalają Garbage Collectorowi zwolnić pamięć natychmiast po zakończeniu żądania/korutyny).
  - **Brak referencji cyklicznych i wycieków w domknięciach:** Zawsze oznaczaj domknięcia jako `static fn` / `static function`, aby nie przechwytywały niejawnie referencji do całych grafów obiektów poprzez `$this`. W `use (...)` przekazuj tylko minimalne potrzebne skalarne dane lub DTO, nigdy ciężkie kontenery czy połączenia.
  - **Deterministyczne zwalnianie zasobów (`try / finally`):** Wszystkie deskryptory plików, strumienie, kursory bazodanowe, gniazda sieciowe i połączenia pożyczone z puli (connection pool) **muszą** być zamykane lub zwracane do puli w bloku `finally` (bez instrukcji `return` wewnątrz `finally`), aby uniknąć wycieku deskryptorów i połączeń przy wyjątkach.
  - **Strumieniowanie zamiast ładowania do RAM:** Nigdy nie wczytuj całych dużych plików ani pełnych tabel z bazy do pamięci (`file_get_contents` na dużych plikach, `fetchAll()` na dużych zbiorach). Zawsze używaj strumieni, kursorów i `Generator` (`yield`) o stałym narzucie pamięci `O(1)`.
- **Bezpieczeństwo aplikacyjne (Secure by Default):**
  - **Wrażliwe dane (`#[\SensitiveParameter]`):** Zawsze oznaczaj parametry przechowujące hasła, tokeny, klucze API, sekrety i dane kart atrybutem **`#[\SensitiveParameter]`**, aby nigdy nie wyciekły do logów ani zrzutów stosu (stack traces).
  - **Zero zaufania do wejścia (SQLi / XSS / Command Injection / SSRF):** Wyłącznie parametryzowane zapytania (prepared statements), ścisła walidacja DTO na granicy wejścia, bezpieczne escapowanie wyjścia kontekstowego, kryptograficznie bezpieczna losowość (`\Random\Randomizer` z `\Random\Engine\Secure`) oraz `hash_equals()` przy porównywaniu tokenów/podpisów (ochrona przed timing attacks).
  - **Zakaz niebezpiecznych funkcji:** Całkowity zakaz używania `eval()`, `extract()`, `unserialize()` bez ścisłej białej listy `allowed_classes: [...]` (preferuj JSON/DTO) oraz dynamicznego wykonywania poleceń powłoki z nieprzefiltrowanymi danymi.

---

## 9. Schemat i Styl Plików Konfiguracyjnych (katalog `config/`)

**Cała konfiguracja aplikacji i frameworka znajduje się wyłącznie w katalogu `config/`** (nigdy nie rozpraszaj ustawień po innych folderach ani nie zaszywaj ich na sztywno w klasach). Zawsze trzymaj się ściśle ustalonego schematu i wyglądu plików w `config/` oraz mechanizmu `Atom\Config\Config` — proaktywnie dodawaj wszystkie bezpieczne, przyszłościowe ustawienia od razu w tym stylu:

- **Struktura i wygląd pliku `config/<nazwa>.php`:**
  - Zawsze zaczynaj od `<?php` i `declare(strict_types=1);`, opcjonalnych importów `use` (np. `use Pdo\Mysql;`, `use Time\Duration;`, enumy), bloku DocBlock szablonu (`/** * <Name> configuration template */`) oraz `return [ ... ];`.
  - **Pełne bloki PHPDoc nad każdym kluczem i podsekcją** z opisem działania, dozwolonych wartości, wartości domyślnej oraz adnotacją `@var <typ>` (`string`, `int`, `bool`, `array`, `\Time\Duration`, itp.).
  - **Wzorzec `default` + `choices` dla sterowników (drivers):** Konfiguracje wielosterownikowe (`database`, `cache`, `session`, `filesystem`, `logger`, `mail`, `queue`, `server` itp.) muszą używać klucza `'default' => env('<PREFIX>_DRIVER', '...')` oraz tablicy `'choices' => [ '<driver>' => [ 'driver' => '<driver>', ... ] ]` (automatycznie rozwiązywanych przez `Atom\Config\Config::get()` do klucza `'driver'`).
  - **Przełączniki funkcji (`'on'`) i zmienne środowiskowe `env()`:** Podsekcje funkcjonalne w `app.php` (oraz innych plikach) grupuj w tablice asocjacyjne z głównym przełącznikiem `'on' => (bool) env('APP_<FEATURE>', false|true)` i spójnym prefiksem zmiennych środowiskowych (`APP_*`, `DB_*`, `CACHE_*`, `SESSION_*`, `REDIS_*`, `SWOOLE_*`, `RUNTIME_*`, `SECURITY_*`).
  - **Rejestracja nowych plików:** Każdy nowy plik `config/<name>.php` rejestruj w `Atom\Config\Config::$configFileName` (oraz opcjonalnie wymagane klucze w `EnvironmentVariables::$environmentEnsuredKeys`), pamiętając o kompatybilności z cache'em konfiguracji w `runtime/cache/config/` (`var_export` / brak nienatywnych domknięć w zwracanej tablicy).
- **Bezpieczne domyślne ustawienia (Secure & Production-First Defaults):**
  - Proaktywnie dodawaj komplet bezpiecznych opcji z produkcyjnymi wartościami domyślnymi: `debug => false`, `forceHttps => true`, ciasteczka sesji `secure => true`, `httpOnly => true`, `sameSite => 'lax'`, `useStrictMode => true` (zgodnie z PHP 8.6), szyfrowanie/regeneracja sesji przy logowaniu, wyłączona emulacja prepared statements (`PDO::ATTR_EMULATE_PREPARES => false`), ścisłe timeouty połączeń, limity puli połączeń (`pool`) pod Swoole/mikrousługi oraz ścieżki robocze wskazujące wyłącznie na `runtime/`.

---

## 10. Testy, Pliki Tymczasowe i Struktura Katalogów

- **Brak wycieków pamięci w długo żyjących procesach (Zero Memory Leaks):**
  - **Zakaz nieograniczonych buforów i tablic w serwisach:** Nigdy nie akumuluj obiektów, logów, eventów ani wyników zapytań w polach długo żyjących obiektów lub zmiennych statycznych. Jeśli lokalna mapa powiązań obiektów jest niezbędna, używaj wyłącznie **`\WeakMap`** oraz **`\WeakReference`** (pozwalają Garbage Collectorowi zwolnić pamięć natychmiast po zakończeniu żądania/korutyny).
  - **Brak referencji cyklicznych i wycieków w domknięciach:** Zawsze oznaczaj domknięcia jako `static fn` / `static function`, aby nie przechwytywały niejawnie referencji do całych grafów obiektów poprzez `$this`. W `use (...)` przekazuj tylko minimalne potrzebne skalarne dane lub DTO, nigdy ciężkie kontenery czy połączenia.
  - **Deterministyczne zwalnianie zasobów (`try / finally`):** Wszystkie deskryptory plików, strumienie, kursory bazodanowe, gniazda sieciowe i połączenia pożyczone z puli (connection pool) **muszą** być zamykane lub zwracane do puli w bloku `finally` (bez instrukcji `return` wewnątrz `finally`), aby uniknąć wycieku deskryptorów i połączeń przy wyjątkach.
  - **Strumieniowanie zamiast ładowania do RAM:** Nigdy nie wczytuj całych dużych plików ani pełnych tabel z bazy do pamięci (`file_get_contents` na dużych plikach, `fetchAll()` na dużych zbiorach). Zawsze używaj strumieni, kursorów i `Generator` (`yield`) o stałym narzucie pamięci `O(1)`.
- **Bezpieczeństwo aplikacyjne (Secure by Default):**
  - **Wrażliwe dane (`#[\SensitiveParameter]`):** Zawsze oznaczaj parametry przechowujące hasła, tokeny, klucze API, sekrety i dane kart atrybutem **`#[\SensitiveParameter]`**, aby nigdy nie wyciekły do logów ani zrzutów stosu (stack traces).
  - **Zero zaufania do wejścia (SQLi / XSS / Command Injection / SSRF):** Wyłącznie parametryzowane zapytania (prepared statements), ścisła walidacja DTO na granicy wejścia, bezpieczne escapowanie wyjścia kontekstowego, kryptograficznie bezpieczna losowość (`\Random\Randomizer` z `\Random\Engine\Secure`) oraz `hash_equals()` przy porównywaniu tokenów/podpisów (ochrona przed timing attacks).
  - **Zakaz niebezpiecznych funkcji:** Całkowity zakaz używania `eval()`, `extract()`, `unserialize()` bez ścisłej białej listy `allowed_classes: [...]` (preferuj JSON/DTO) oraz dynamicznego wykonywania poleceń powłoki z nieprzefiltrowanymi danymi.

---

## 9. Schemat i Styl Plików Konfiguracyjnych (katalog `config/`)

**Cała konfiguracja aplikacji i frameworka znajduje się wyłącznie w katalogu `config/`** (nigdy nie rozpraszaj ustawień po innych folderach ani nie zaszywaj ich na sztywno w klasach). Zawsze trzymaj się ściśle ustalonego schematu i wyglądu plików w `config/` oraz mechanizmu `Atom\Config\Config` — proaktywnie dodawaj wszystkie bezpieczne, przyszłościowe ustawienia od razu w tym stylu:

- **Struktura i wygląd pliku `config/<nazwa>.php`:**
  - Zawsze zaczynaj od `<?php` i `declare(strict_types=1);`, opcjonalnych importów `use` (np. `use Pdo\Mysql;`, `use Time\Duration;`, enumy), bloku DocBlock szablonu (`/** * <Name> configuration template */`) oraz `return [ ... ];`.
  - **Pełne bloki PHPDoc nad każdym kluczem i podsekcją** z opisem działania, dozwolonych wartości, wartości domyślnej oraz adnotacją `@var <typ>` (`string`, `int`, `bool`, `array`, `\Time\Duration`, itp.).
  - **Wzorzec `default` + `choices` dla sterowników (drivers):** Konfiguracje wielosterownikowe (`database`, `cache`, `session`, `filesystem`, `logger`, `mail`, `queue`, `server` itp.) muszą używać klucza `'default' => env('<PREFIX>_DRIVER', '...')` oraz tablicy `'choices' => [ '<driver>' => [ 'driver' => '<driver>', ... ] ]` (automatycznie rozwiązywanych przez `Atom\Config\Config::get()` do klucza `'driver'`).
  - **Przełączniki funkcji (`'on'`) i zmienne środowiskowe `env()`:** Podsekcje funkcjonalne w `app.php` (oraz innych plikach) grupuj w tablice asocjacyjne z głównym przełącznikiem `'on' => (bool) env('APP_<FEATURE>', false|true)` i spójnym prefiksem zmiennych środowiskowych (`APP_*`, `DB_*`, `CACHE_*`, `SESSION_*`, `REDIS_*`, `SWOOLE_*`, `RUNTIME_*`, `SECURITY_*`).
  - **Rejestracja nowych plików:** Każdy nowy plik `config/<name>.php` rejestruj w `Atom\Config\Config::$configFileName` (oraz opcjonalnie wymagane klucze w `EnvironmentVariables::$environmentEnsuredKeys`), pamiętając o kompatybilności z cache'em konfiguracji w `runtime/cache/config/` (`var_export` / brak nienatywnych domknięć w zwracanej tablicy).
- **Bezpieczne domyślne ustawienia (Secure & Production-First Defaults):**
  - Proaktywnie dodawaj komplet bezpiecznych opcji z produkcyjnymi wartościami domyślnymi: `debug => false`, `forceHttps => true`, ciasteczka sesji `secure => true`, `httpOnly => true`, `sameSite => 'lax'`, `useStrictMode => true` (zgodnie z PHP 8.6), szyfrowanie/regeneracja sesji przy logowaniu, wyłączona emulacja prepared statements (`PDO::ATTR_EMULATE_PREPARES => false`), ścisłe timeouty połączeń, limity puli połączeń (`pool`) pod Swoole/mikrousługi oraz ścieżki robocze wskazujące wyłącznie na `runtime/`.

---

## 10. Testy, Pliki Tymczasowe i Struktura Katalogów

- **Testy frameworka Atom (`tests/Atom`):** Wszystkie testy dla przestrzeni nazw `Atom\` (rdzeń i komponenty frameworka Atom) **muszą być tworzone wyłącznie w katalogu `tests/Atom`** (z przestrzenią nazw `Tests\Atom\...`, odzwierciedlającą strukturę podkatalogów z `Atom/`).
- Nie umieszczaj testów komponentów Atom bezpośrednio w głównym katalogu `tests/` ani w `tests/App/`.
- **Pliki tymczasowe i wygenerowane (`runtime/`):** Wszystkie pliki tymczasowe aplikacji, uploady robocze, lokalne pliki wygenerowane przez Atom, logi robocze oraz tymczasowe pliki testowe/pomocnicze **muszą być tworzone wyłącznie w katalogu `runtime/`** (np. `runtime/temp`, `runtime/tempUpload`, `runtime/cache`, `runtime/log`). Nie zaśmiecaj głównego katalogu projektu, `storage/` ani systemowego `/tmp` luźnymi plikami tymczasowymi i zawsze dbaj o ich deterministyczne sprzątanie po zakończeniu operacji.
- **Nazewnictwo plików SQL w modułach:** Nazwy plików `.sql` (np. migracji) w modułach muszą zaczynać się od przedrostka utworzonego z inicjałów nazwy modułu (małymi literami) bezpośrednio przed numerem sekwencyjnym — np. dla modułu `ErrorDebugger` zamiast `0001_create_error_debugger_snapshots.down.sql` należy stosować `ed0001_create_error_debugger_snapshots.down.sql` (analogicznie dla `.up.sql` i pozostałych modułów).



