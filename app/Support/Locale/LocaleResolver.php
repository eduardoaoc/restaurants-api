<?php

namespace App\Support\Locale;

use App\Exceptions\Public\InvalidPublicLocaleException;
use Illuminate\Support\Collection;

/**
 * Resolves the effective locale of a public request and picks the best
 * translation for a given locale, following the fallback chain:
 * exact locale -> base language -> es -> first available translation.
 */
class LocaleResolver
{
    public const PATTERN = '/^[A-Za-z]{2,3}(-[A-Za-z]{2,4})?$/';

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
     * Normalize casing: language lowercase, region uppercase (es-ES, pt-BR).
     */
    public static function normalize(string $locale): string
    {
        $parts = explode('-', $locale);
        $language = strtolower($parts[0]);

        if (isset($parts[1]) && $parts[1] !== '') {
            return $language.'-'.strtoupper($parts[1]);
        }

        return $language;
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
     * @template TTranslation of object
     *
     * @param  Collection<int, TTranslation>  $translations
     * @return TTranslation|null
     */
    public static function pickTranslation(Collection $translations, string $locale)
    {
        if ($translations->isEmpty()) {
            return null;
        }

        $locale = self::normalize($locale);
        $base = strtolower(explode('-', $locale)[0]);

        return $translations->first(fn ($translation) => strcasecmp($translation->locale, $locale) === 0)
            ?? $translations->first(fn ($translation) => strtolower(explode('-', $translation->locale)[0]) === $base)
            ?? $translations->first(fn ($translation) => strtolower($translation->locale) === self::DEFAULT_LOCALE)
            ?? $translations->first();
    }
}
