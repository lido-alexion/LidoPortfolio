import { messages } from './messages.js';

const DEFAULT_LOCALE = 'en';

function currentLocale() {
    if (typeof document !== 'undefined' && document.documentElement?.lang) {
        return document.documentElement.lang.split('-')[0].toLowerCase();
    }
    if (typeof navigator !== 'undefined' && navigator.language) {
        return navigator.language.split('-')[0].toLowerCase();
    }
    return DEFAULT_LOCALE;
}

export function t(key, variables = {}, locale = currentLocale()) {
    const dictionary = messages[locale] || messages[DEFAULT_LOCALE];
    let value = dictionary[key] || messages[DEFAULT_LOCALE][key] || key;

    return Object.entries(variables).reduce(
        (text, [name, replacement]) => text.replaceAll(`{${name}}`, String(replacement)),
        value,
    );
}

export function hasTranslation(key, locale = DEFAULT_LOCALE) {
    return Boolean(messages[locale]?.[key] || messages[DEFAULT_LOCALE]?.[key]);
}

export { messages };
