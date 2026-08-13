import { driver, type DriveStep } from 'driver.js';
import 'driver.js/dist/driver.css';
import { useLocaleStore } from '../stores/locale';
import { pagePaths, type DashboardPage } from './pages';
import { pageTourSteps } from './pageTours';

const BASE_KEY = 'wero_onboarding_completed';
const pageKey = (page: DashboardPage) => `wero_onboarding_page_${page}`;

function runTour(steps: DriveStep[], onFinished: () => void): void {
    if (! steps.length) {
        onFinished();
        return;
    }

    const locale = useLocaleStore();
    const t = (key: string) => locale.t(key);

    const tour = driver({
        showProgress: steps.length > 1,
        animate: true,
        smoothScroll: true,
        overlayOpacity: 0.6,
        stagePadding: 6,
        stageRadius: 10,
        allowClose: true,
        skipMissingElement: true,
        popoverClass: 'wero-driver-popover',
        nextBtnText: t('onboarding.next'),
        prevBtnText: t('onboarding.prev'),
        doneBtnText: t('onboarding.done'),
        progressText: t('onboarding.progress'),
        onDestroyed: onFinished,
        steps,
    });

    tour.drive();
}

export function hasSeenOnboarding(): boolean {
    return localStorage.getItem(BASE_KEY) === '1';
}

export function startOnboarding(onFinished?: () => void): void {
    const locale = useLocaleStore();
    const t = (key: string) => locale.t(key);

    const steps: DriveStep[] = [
        { element: '[data-tour="logo"]', popover: { title: t('onboarding.steps.logo.title'), description: t('onboarding.steps.logo.text'), side: 'bottom', align: 'start' } },
        { element: '[data-tour="nav"]', popover: { title: t('onboarding.steps.nav.title'), description: t('onboarding.steps.nav.text'), side: 'right', align: 'start' } },
        { element: '[data-tour="sidebar-toggle"]', popover: { title: t('onboarding.steps.toggle.title'), description: t('onboarding.steps.toggle.text'), side: 'right', align: 'start' } },
        { element: '[data-tour="search"]', popover: { title: t('onboarding.steps.search.title'), description: t('onboarding.steps.search.text'), side: 'bottom', align: 'start' } },
        { element: '[data-tour="language"]', popover: { title: t('onboarding.steps.language.title'), description: t('onboarding.steps.language.text'), side: 'bottom', align: 'center' } },
        { element: '[data-tour="theme"]', popover: { title: t('onboarding.steps.theme.title'), description: t('onboarding.steps.theme.text'), side: 'bottom', align: 'center' } },
        { element: '[data-tour="profile"]', popover: { title: t('onboarding.steps.profile.title'), description: t('onboarding.steps.profile.text'), side: 'right', align: 'end' } },
        { element: '[data-tour="content"]', popover: { title: t('onboarding.steps.content.title'), description: t('onboarding.steps.content.text'), side: 'top', align: 'start' } },
    ];

    runTour(steps, () => {
        localStorage.setItem(BASE_KEY, '1');
        onFinished?.();
    });
}

export function restartOnboarding(): void {
    localStorage.removeItem(BASE_KEY);
    startOnboarding();
}

export function restartAllTours(onFinished?: () => void): void {
    localStorage.removeItem(BASE_KEY);
    (Object.keys(pagePaths) as DashboardPage[]).forEach((page) => localStorage.removeItem(pageKey(page)));
    startOnboarding(onFinished);
}

export function hasSeenPageTour(page: DashboardPage): boolean {
    return localStorage.getItem(pageKey(page)) === '1';
}

export function maybeStartPageTour(page: DashboardPage): void {
    if (hasSeenPageTour(page)) return;

    const build = pageTourSteps[page];
    if (! build) {
        localStorage.setItem(pageKey(page), '1');
        return;
    }

    const locale = useLocaleStore();
    const t = (key: string) => locale.t(key);
    const steps = build(t);

    runTour(steps, () => localStorage.setItem(pageKey(page), '1'));
}

export function restartPageTour(page: DashboardPage): void {
    localStorage.removeItem(pageKey(page));
    maybeStartPageTour(page);
}
