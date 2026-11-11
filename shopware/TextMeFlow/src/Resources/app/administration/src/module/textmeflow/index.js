import './page/textmeflow-index';
import enGB from './snippet/en-GB.json';
import deDE from './snippet/de-DE.json';
import nlNL from './snippet/nl-NL.json';
import frFR from './snippet/fr-FR.json';

Shopware.Module.register('textmeflow', {
    type: 'plugin',
    name: 'textmeflow',
    title: 'textmeflow.general.title',
    description: 'textmeflow.general.description',
    color: '#25a366',
    icon: 'regular-comments',
    routePrefixPath: 'sw/settings/textmeflow',

    snippets: {
        'en-GB': enGB,
        'de-DE': deDE,
        'nl-NL': nlNL,
        'fr-FR': frFR,
    },

    routes: {
        index: {
            component: 'textmeflow-index',
            path: 'index',
            meta: {
                parentPath: 'sw.settings.index.plugins',
            },
        },
    },

    settingsItem: {
        group: 'plugins',
        to: 'textmeflow.index',
        icon: 'regular-comments',
        label: 'textmeflow.general.title',
    },
});
