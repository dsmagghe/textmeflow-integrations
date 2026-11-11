import './page/textmeflow-index';

// Snippets in ./snippet/*.json are loaded automatically by Shopware.

// Shopware only registers modules named "[namespace]-[name]"; a name without a dash is silently dropped.
Shopware.Module.register('textmeflow-settings', {
    type: 'plugin',
    name: 'textmeflow-settings',
    title: 'textmeflow.general.title',
    description: 'textmeflow.general.description',
    color: '#25a366',
    icon: 'regular-comments',
    routePrefixPath: 'sw/settings/textmeflow',

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
        to: 'textmeflow.settings.index',
        icon: 'regular-comments',
        // Short label: the settings tile has little room.
        label: 'textmeflow.general.settingsLabel',
    },
});
