import Index from './pages/darkroom/Index.vue';

Statamic.booting(() => {
    Statamic.$inertia.register('darkroom/Index', Index);
});
