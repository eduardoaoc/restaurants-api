<?php

namespace App\Support\Locale;

use App\Exceptions\Public\InvalidPublicLocaleException;
use Illuminate\Support\Collection;

/**
 * Resolves the effective locale of a public request and picks the best
 * translation for a given locale, following the fallback chain: exact
 * locale -> base language -> restaurant default_locale (if given) -> base
 * of that default -> es -> first available translation.
 */
class LocaleResolver
{
    /**
     * language[-region[-variant]] — e.g. "es", "es-ES", "ca-ES-valencia".
     * The third (variant) segment is what Bloco 18's ca-ES-valencia needs;
     * everything that matched before (bare "es", "pt-BR", "pt-br") still
     * matches unchanged, since that segment is optional.
     */
    public const PATTERN = '/^[A-Za-z]{2,3}(-[A-Za-z]{2,4})?(-[A-Za-z]{2,8})?$/';

    private const DEFAULT_LOCALE = 'es';

    /**
     * Resolve the effective locale for a request: query wins over
     * Accept-Language, which wins over the hardcoded default.
     */
    public static function resolve(?string $queryLocale, ?string $acceptLanguage): string
    {
        if ($queryLocale !== null && $queryLocale !== '') {
            if (! self::isValidFormat($queryLocale)) {
                throw new InvalidPublicLocaleException;
            }

            return self::normalize($queryLocale);
        }

        return self::firstValidFromAcceptLanguage($acceptLanguage) ?? self::DEFAULT_LOCALE;
    }

    public static function isValidFormat(string $locale): bool
    {
        return (bool) preg_match(self::PATTERN, $locale);
    }

    /**
     * Normalize casing: language lowercase, region uppercase, variant
     * lowercase (es-ES, pt-BR, ca-ES-valencia).
     */
    public static function normalize(string $locale): string
    {
        $parts = explode('-', $locale);
        $language = strtolower($parts[0]);

        if (! isset($parts[1]) || $parts[1] === '') {
            return $language;
        }

        $normalized = $language.'-'.strtoupper($parts[1]);

        if (isset($parts[2]) && $parts[2] !== '') {
            $normalized .= '-'.strtolower($parts[2]);
        }

        return $normalized;
    }

    /**
     * Parse an Accept-Language header and return the first valid, normalized
     * locale tag. Not a full RFC 4647 parser: quality values are stripped
     * and malformed tags are skipped rather than erroring.
     */
    public static function firstValidFromAcceptLanguage(?string $header): ?string
    {
        if ($header === null || trim($header) === '') {
            return null;
        }

        foreach (explode(',', $header) as $part) {
            $tag = trim(explode(';', $part)[0]);

            if ($tag === '' || $tag === '*') {
                continue;
            }

            if (self::isValidFormat($tag)) {
                return self::normalize($tag);
            }
        }

        return null;
    }

    /**
     * Pick the best translation for a locale from a collection of models
     * exposing `locale`, `name` and `description` attributes.
     *
     * Fallback chain: exact requested locale -> its base language ->
     * $restaurantDefaultLocale (if given, e.g. RestaurantSettings::
     * default_locale) -> that default's base language -> the hardcoded
     * "es" -> the first translation available at all. $restaurantDefaultLocale
     * is optional (defaults to null, skipping straight to "es") so every
     * pre-Bloco-18 call site keeps behaving exactly as before until it is
     * updated to pass the restaurant's own default.
     *
     * @template TTranslation of object
     *
     * @param  Collection<int, TTranslation>  $translations
     * @return TTranslation|null
     */
    public static function pickTranslation(Collection $translations, string $locale, ?string $restaurantDefaultLocale = null)
    {
        if ($translations->isEmpty()) {
            return null;
        }

        $locale = self::normalize($locale);
        $base = strtolower(explode('-', $locale)[0]);

        $found = $translations->first(fn ($translation) => strcasecmp($translation->locale, $locale) === 0)
            ?? $translations->first(fn ($translation) => strtolower(explode('-', $translation->locale)[0]) === $base);

        if ($found) {
            return $found;
        }

        if ($restaurantDefaultLocale !== null) {
            $normalizedDefault = self::normalize($restaurantDefaultLocale);
            $defaultBase = strtolower(explode('-', $normalizedDefault)[0]);

            $found = $translations->first(fn ($translation) => strcasecmp($translation->locale, $normalizedDefault) === 0)
                ?? $translations->first(fn ($translation) => strtolower(explode('-', $translation->locale)[0]) === $defaultBase);

            if ($found) {
                return $found;
            }
        }

        return $translations->first(fn ($translation) => strtolower($translation->locale) === self::DEFAULT_LOCALE)
            ?? $translations->first();
    }
}
