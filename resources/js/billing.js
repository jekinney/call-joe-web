// Paying for a plan: Braintree's card form (Drop-in) runs in the browser, so card details go straight to Braintree and
// never touch our server. The plan page (livewire/app/plan) sends 'billing-start' with a client token; when the user
// presses Subscribe, the one-time payment nonce goes back to the page as a Livewire event.
let dropin = null;

const tell = (name, params = {}) => window.Livewire?.dispatch(name, params);

window.addEventListener('billing-start', async (event) => {
    // The page draws the card form's box in the same response that sends this event, so give it a moment.
    let container = null;
    for (let tries = 0; tries < 20 && !(container = document.getElementById('billing-dropin')); tries++) {
        await new Promise((resolve) => setTimeout(resolve, 50));
    }
    if (!container) return tell('billing-error', { message: 'The card form is missing.' });
    try {
        const { default: braintree } = await import('braintree-web-drop-in');
        await dropin?.teardown().catch(() => {});
        dropin = await braintree.create({ authorization: event.detail.token, container, card: { cardholderName: { required: false } } });
        tell('billing-ready');
    } catch (error) {
        dropin = null;
        tell('billing-error', { message: String(error?.message ?? error) });
    }
});

window.addEventListener('billing-pay', async () => {
    try {
        const { nonce } = await dropin.requestPaymentMethod();
        tell('billing-nonce', { nonce });
    } catch (error) {
        tell('billing-error', { message: String(error?.message ?? error) });
    }
});

window.addEventListener('billing-stop', () => {
    dropin?.teardown().catch(() => {});
    dropin = null;
});

document.addEventListener('livewire:navigating', () => {
    dropin?.teardown().catch(() => {});
    dropin = null;
});
