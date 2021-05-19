import template from './sw-plugin.html.twig';

const { Component } = Shopware;

Component.override('sw-plugin-list', {
    template
});
