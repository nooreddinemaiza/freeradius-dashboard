<?php

namespace Core\Routing\Http;

use Core\File;
use Core\Helper\Data;


class Request
{
    protected Data $query;
    protected Data $post;
    protected Data $files;
    protected Data $cookies;
    protected Data $server;
    protected Data $headers;
    protected Data $body;
    protected Data $attributes;
    protected string $rawBody;
    protected ?string $contentType = null;
    protected ?int $contentLength = null;

    public function __construct()
    {
        // Code existant du constructeur...
        $this->query = Data::create($_GET);
        $this->post = Data::create($_POST);
        $this->files = Data::create($this->normalizeFiles($_FILES));
        $this->cookies = Data::create($_COOKIE);
        $this->server = Data::create($_SERVER);
        $this->headers = Data::create($this->parseHeaders());
        $this->attributes = Data::create();

        $this->rawBody = file_get_contents('php://input') ?: '';
        $this->parseContentInfo();
        $this->body = $this->parseBody();
    }

    /**
     * Factory method for creating Request instances
     */
    public static function create(): self
    {
        return new self();
    }

    /**
     * Parse headers from $_SERVER
     */
    protected function parseHeaders(): array
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = $this->normalizeHeaderName(substr($key, 5));
                $headers[$name] = $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'])) {
                $name = $this->normalizeHeaderName($key);
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    /**
     * Normalize header name (convert underscores to hyphens and make lowercase)
     */
    protected function normalizeHeaderName(string $name): string
    {
        return strtolower(str_replace('_', '-', $name));
    }

    /**
     * Parse content type and length information
     */
    protected function parseContentInfo(): void
    {
        $this->contentType = $this->server->asString('CONTENT_TYPE');
        $this->contentLength = $this->server->asInt('CONTENT_LENGTH');
    }

    /**
     * Parse request body based on content type
     */
    protected function parseBody(): Data
    {
        // Si pas de body, retourner les données POST
        if (empty($this->rawBody)) {
            return $this->post;
        }

        // JSON
        if ($this->isJson()) {
            try {
                $decoded = json_decode($this->rawBody, true, 512, JSON_THROW_ON_ERROR);
                return Data::create($decoded);
            } catch (\JsonException $e) {
                return Data::create();
            }
        }

        // XML
        if ($this->isXml()) {
            return $this->parseXmlBody();
        }

        // Form data
        if ($this->isFormData()) {
            parse_str($this->rawBody, $parsed);
            return Data::create($parsed);
        }

        // Multipart (handled by PHP automatically in $_POST)
        if ($this->isMultipart()) {
            return $this->post;
        }

        // Default: return POST data
        return $this->post;
    }

    /**
     * Parse XML body content
     */
    protected function parseXmlBody(): Data
    {
        try {
            $xml = simplexml_load_string($this->rawBody, 'SimpleXMLElement', LIBXML_NOCDATA);
            if ($xml === false) {
                return Data::create();
            }
            return Data::create(json_decode(json_encode($xml), true));
        } catch (\Exception $e) {
            return Data::create();
        }
    }

    /**
     * Normalize $_FILES array structure
     */
    protected function normalizeFiles(array $files): array
    {
        $normalized = [];

        foreach ($files as $key => $file) {
            if (is_array($file['name'])) {
                $normalized[$key] = $this->normalizeNestedFiles($file);
            } else {
                $normalized[$key] = $file;
            }
        }

        return $normalized;
    }

    /**
     * Normalize nested file arrays
     */
    protected function normalizeNestedFiles(array $file): array
    {
        $normalized = [];

        foreach ($file['name'] as $index => $name) {
            $normalized[$index] = [
                'name' => $name,
                'type' => $file['type'][$index] ?? '',
                'size' => $file['size'][$index] ?? 0,
                'tmp_name' => $file['tmp_name'][$index] ?? '',
                'error' => $file['error'][$index] ?? UPLOAD_ERR_NO_FILE
            ];
        }

        return $normalized;
    }

    // === HTTP Method Methods ===

    public function method(): string
    {
        return strtoupper($this->server->asString('REQUEST_METHOD', 'GET'));
    }

    public function isMethod(string $method): bool
    {
        return $this->method() === strtoupper($method);
    }

    public function isGet(): bool
    {
        return $this->isMethod('GET');
    }

    public function isPost(): bool
    {
        return $this->isMethod('POST');
    }

    public function isPut(): bool
    {
        return $this->isMethod('PUT');
    }

    public function isPatch(): bool
    {
        return $this->isMethod('PATCH');
    }

    public function isDelete(): bool
    {
        return $this->isMethod('DELETE');
    }

    public function isOptions(): bool
    {
        return $this->isMethod('OPTIONS');
    }

    public function isHead(): bool
    {
        return $this->isMethod('HEAD');
    }

    // === URI Methods ===

    public function uri(): string
    {
        return $this->server->asString('REQUEST_URI', '/');
    }

    public function url(): string
    {
        $scheme = $this->isSecure() ? 'https' : 'http';
        $host = $this->host();
        $port = $this->port();
        $uri = $this->uri();

        $url = $scheme . '://' . $host;

        if (($scheme === 'http' && $port !== 80) || ($scheme === 'https' && $port !== 443)) {
            $url .= ':' . $port;
        }

        return $url . $uri;
    }
    public function mainUrl(): string
    {
        $scheme = $this->isSecure() ? 'https' : 'http';
        $host = $this->host();
        $port = $this->port();

        $url = $scheme . '://' . $host;

        if (($scheme === 'http' && $port !== 80) || ($scheme === 'https' && $port !== 443)) {
            $url .= ':' . $port;
        }

        return $url;
    }
    public function fullUrl(): string
    {
        return $this->url();
    }

    public function path(): string
    {
        $path = parse_url($this->uri(), PHP_URL_PATH) ?? '/';

        // Supprimer le slash de fin sauf s'il s'agit de la racine
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        return $path;
    }

    public function pathInfo(): string
    {
        return $this->server->asString('PATH_INFO', $this->path());
    }

    public function basePath(): string
    {
        // Utilisation de Path pour obtenir le chemin de base
        return File::getBaseDir();
    }

    public function baseUrl(): string
    {
        $scheme = $this->isSecure() ? 'https' : 'http';
        $host = $this->host();
        $port = $this->port();

        $url = $scheme . '://' . $host;

        if (($scheme === 'http' && $port !== 80) || ($scheme === 'https' && $port !== 443)) {
            $url .= ':' . $port;
        }

        return $url . File::getBaseDir();
    }

    public function host(): string
    {
        return $this->server->asString(
            'HTTP_HOST',
            $this->server->asString('SERVER_NAME', 'localhost')
        );
    }

    public function port(): int
    {
        return $this->server->asInt('SERVER_PORT', 80);
    }

    public function isSecure(): bool
    {
        return $this->server->asString('HTTPS') === 'on' ||
            $this->server->asInt('SERVER_PORT') === 443 ||
            $this->header('x-forwarded-proto') === 'https';
    }

    // === Content Type Methods ===

    public function contentType(): string
    {
        return $this->contentType ?? '';
    }

    public function contentLength(): int
    {
        return $this->contentLength ?? 0;
    }

    public function isJson(): bool
    {
        return str_contains(strtolower($this->contentType()), 'application/json');
    }

    public function isXml(): bool
    {
        $contentType = strtolower($this->contentType());
        return str_contains($contentType, 'application/xml') ||
            str_contains($contentType, 'text/xml');
    }

    public function isFormData(): bool
    {
        return str_contains(strtolower($this->contentType()), 'application/x-www-form-urlencoded');
    }

    public function isMultipart(): bool
    {
        return str_contains(strtolower($this->contentType()), 'multipart/form-data');
    }

    public function isAjax(): bool
    {
        return strtolower($this->header('x-requested-with')) === 'xmlhttprequest';
    }

    public function wantsJson(): bool
    {
        $acceptable = $this->header('accept');
        return str_contains(strtolower($acceptable), 'application/json');
    }

    public function expectsJson(): bool
    {
        return $this->isAjax() || $this->wantsJson();
    }

    // === Data Access Methods ===

    public function input(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return array_merge($this->query->all(), $this->body->all());
        }

        // Chercher d'abord dans le body, puis dans query
        if ($this->body->has($key)) {
            return $this->body->get($key, $default);
        }

        return $this->query->get($key, $default);
    }

    public function query(?string $key = null, mixed $default = null): mixed
    {
        return $key ? $this->query->get($key, $default) : $this->query->all();
    }
    public function post(?string $key = null, mixed $default = null): mixed
    {
        return $key ? $this->post->get($key, $default) : $this->post->all();
    }

    public function json(?string $key = null, mixed $default = null): mixed
    {
        return $key ? $this->body->get($key, $default) : $this->body->all();
    }

    public function body(): Data
    {
        return $this->body;
    }

    public function raw(): string
    {
        return $this->rawBody;
    }

    public function all(): array
    {
        return array_merge($this->query->all(), $this->body->all());
    }

    public function only(array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            if ($this->has($key)) {
                $result[$key] = $this->input($key);
            }
        }
        return $result;
    }

    public function except(array $keys): array
    {
        $all = $this->all();
        foreach ($keys as $key) {
            unset($all[$key]);
        }
        return $all;
    }

    public function has(string $key): bool
    {
        return $this->body->has($key) || $this->query->has($key);
    }

    public function hasAny(array $keys): bool
    {
        foreach ($keys as $key) {
            if ($this->has($key)) {
                return true;
            }
        }
        return false;
    }

    public function missing(string $key): bool
    {
        return !$this->has($key);
    }

    public function filled(string $key): bool
    {
        return $this->has($key) && !empty($this->input($key));
    }

    // === Typed Access Methods ===

    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);
        return is_string($value) ? $value : (string)$value;
    }

    public function integer(string $key, int $default = 0): int
    {
        $value = $this->input($key, $default);
        return is_numeric($value) ? (int)$value : $default;
    }

    public function float(string $key, float $default = 0.0): float
    {
        $value = $this->input($key, $default);
        return is_numeric($value) ? (float)$value : $default;
    }

    public function boolean(string $key, bool $default = false): bool
    {
        $value = $this->input($key, $default);

        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    public function array(string $key, array $default = []): array
    {
        $value = $this->input($key, $default);
        return is_array($value) ? $value : $default;
    }

    public function date(string $key, ?string $format = null): ?\DateTimeImmutable
    {
        $value = $this->input($key);

        if ($value === null) {
            return null;
        }

        try {
            if ($format) {
                return \DateTimeImmutable::createFromFormat($format, (string)$value) ?: null;
            }
            return new \DateTimeImmutable((string)$value);
        } catch (\Exception) {
            return null;
        }
    }

    // === File Methods ===

    public function file(string $key): mixed
    {
        return $this->files->get($key);
    }

    public function hasFile(string $key): bool
    {
        $file = $this->file($key);
        return is_array($file) && isset($file['error']) && $file['error'] !== UPLOAD_ERR_NO_FILE;
    }

    public function allFiles(): array
    {
        return $this->files->all();
    }

    // === Header Methods ===

    public function header(string $key, string $default = ''): string
    {
        return $this->headers->asString(strtolower($key), $default);
    }

    public function hasHeader(string $key): bool
    {
        return $this->headers->has(strtolower($key));
    }

    public function headers(): Data
    {
        return $this->headers;
    }

    public function allHeaders(): array
    {
        return $this->headers->all();
    }

    public function bearerToken(): ?string
    {
        $authorization = $this->header('authorization');

        if (str_starts_with($authorization, 'Bearer ')) {
            return substr($authorization, 7);
        }

        return null;
    }

    // === Cookie Methods ===

    public function cookie(string $key, mixed $default = null): mixed
    {
        return $this->cookies->get($key, $default);
    }

    public function hasCookie(string $key): bool
    {
        return $this->cookies->has($key);
    }

    public function cookies(): Data
    {
        return $this->cookies;
    }

    public function allCookies(): array
    {
        return $this->cookies->all();
    }

    // === Server Methods ===

    public function server(?string $key = null, mixed $default = null): mixed
    {
        return $key ? $this->server->get($key, $default) : $this->server->all();
    }

    public function ip(): string
    {
        // Check for various proxy headers
        $ipKeys = [
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_CLIENT_IP',            // Proxy
            'HTTP_X_FORWARDED_FOR',      // Load balancer/proxy
            'HTTP_X_FORWARDED',          // Proxy
            'HTTP_X_CLUSTER_CLIENT_IP',  // Cluster
            'HTTP_FORWARDED_FOR',        // Proxy
            'HTTP_FORWARDED',            // Proxy
            'REMOTE_ADDR'                // Standard
        ];

        foreach ($ipKeys as $key) {
            $ip = $this->server->asString($key);
            if (!empty($ip)) {
                // Handle comma-separated IPs (X-Forwarded-For can contain multiple IPs)
                $ips = explode(',', $ip);
                $ip = trim($ips[0]);

                // Validate IP
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }

                // If validation fails, try with private ranges allowed
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return $this->server->asString('REMOTE_ADDR', '127.0.0.1');
    }

    public function userAgent(): string
    {
        return $this->server->asString('HTTP_USER_AGENT');
    }

    public function referer(): string
    {
        return $this->server->asString('HTTP_REFERER');
    }

    /**
     * Récupère la valeur d'un paramètre depuis une URL
     *
     * @param string $paramName Nom du paramètre à extraire
     *
     * @return string|null
     */
    public function getUrlParam(string $paramName): ?string
    {
        $query = parse_url($this->referer(), PHP_URL_QUERY);

        if ($query === null) {
            return null;
        }

        parse_str($query, $params);

        return $params[$paramName] ?? null;
    }

    // === Attributes (for storing custom data) ===

    public function attributes(): Data
    {
        return $this->attributes;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes->get($key, $default);
    }

    public function setAttribute(string $key, mixed $value): self
    {
        $this->attributes->set($key, $value);
        return $this;
    }

    public function hasAttribute(string $key): bool
    {
        return $this->attributes->has($key);
    }

    // === Validation Helpers ===
    public function validate(array $rules): array
    {
        // Utiliser directement la validation de la classe Data
        $allData = Data::create($this->all());
        return $allData->validate($rules);
    }

    // REMPLACER validateEmail() par :
    public function validateEmail(string $field): bool
    {
        $errors = $this->validate([$field => 'required|email']);
        return empty($errors);
    }


    public function validatePassword(string $field, int $minLength = 8): bool
    {
        $errors = $this->validate([$field => "required|string|min:{$minLength}"]);
        return empty($errors);
    }


    public function validatePasswordConfirmation(string $passwordField, string $confirmationField): bool
    {
        $errors = $this->validate([
            $passwordField => 'required|string|min:8',
            $confirmationField => 'required|confirmed'
        ]);
        return empty($errors);
    }

    public function requireFields(array $fields): bool
    {
        $rules = [];
        foreach ($fields as $field) {
            $rules[$field] = 'required';
        }
        $errors = $this->validate($rules);
        return empty($errors);
    }

    // === Méthodes utilitaires de validation ===
    protected function validateField(string $field, mixed $value, string $rule): bool
    {
        $data = Data::create([$field => $value]);
        $errors = $data->validate([$field => $rule]);
        return empty($errors);
    }
    public function passes(array $rules): bool
    {
        return empty($this->validate($rules));
    }

    public function getValidationErrors(array $rules): array
    {
        return $this->validate($rules);
    }

    // === Magic Methods ===

    public function __get(string $name): mixed
    {
        return $this->input($name);
    }

    public function __isset(string $name): bool
    {
        return $this->has($name);
    }

    public function __toString(): string
    {
        return $this->rawBody;
    }

    public function __debugInfo(): array
    {
        return [
            'method' => $this->method(),
            'uri' => $this->uri(),
            'query' => $this->query->all(),
            'body' => $this->body->all(),
            'files' => $this->files->all(),
            'cookies' => $this->cookies->all(),
            'headers' => $this->headers->all(),
            'attributes' => $this->attributes->all()
        ];
    }
}
