function translate(key) {
    const localizedTranslations = globalThis.i18n || {};
    if (Object.prototype.hasOwnProperty.call(localizedTranslations, key)) {
        return localizedTranslations[key];
    }

    const englishTranslations = globalThis.wallosI18nEnglish || {};
    return Object.prototype.hasOwnProperty.call(englishTranslations, key)
        ? englishTranslations[key]
        : "[Translation Missing]";
}
