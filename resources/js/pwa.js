// Installable app: registers the service worker (public/sw.js) and offers an "Install app" button when the browser
// allows it. The button is any element with [data-install-app]; it stays hidden until installing is possible.
let deferred = null;

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}));
}

function showInstallButtons(show) {
    document.querySelectorAll('[data-install-app]').forEach((el) => (el.hidden = !show));
}

window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    deferred = event;
    showInstallButtons(true);
});

window.addEventListener('appinstalled', () => {
    deferred = null;
    showInstallButtons(false);
});

document.addEventListener('click', async (event) => {
    if (!event.target.closest('[data-install-app]') || !deferred) return;
    deferred.prompt();
    await deferred.userChoice;
    deferred = null;
    showInstallButtons(false);
});

// Livewire navigation swaps the page: keep the button's state.
document.addEventListener('livewire:navigated', () => showInstallButtons(deferred !== null));
