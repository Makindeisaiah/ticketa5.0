
(() => {
    "use strict";

    const form = document.getElementById("checkoutForm");
    if (!form) return;

    const button = document.getElementById("payButton");
    const buttonText = document.getElementById("payButtonText");
    const message = document.getElementById("checkoutMessage");
    let submitting = false;

    form.addEventListener("submit", (event) => {
        message.textContent = "";

        if (!form.checkValidity()) {
            event.preventDefault();
            form.reportValidity();
            return;
        }

        const fullName = form.elements.full_name.value.trim();
        const email = form.elements.email.value.trim();
        const phone = form.elements.phone.value.trim();

        if (fullName.length < 2) {
            event.preventDefault();
            message.textContent = "Please enter your full name.";
            form.elements.full_name.focus();
            return;
        }

        if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            event.preventDefault();
            message.textContent = "Please enter a valid email address.";
            form.elements.email.focus();
            return;
        }

        const digits = phone.replace(/\D/g, "");
        if (digits.length < 8 || digits.length > 15) {
            event.preventDefault();
            message.textContent = "Please enter a valid phone number.";
            form.elements.phone.focus();
            return;
        }

        if (submitting) {
            event.preventDefault();
            return;
        }

        submitting = true;
        button.disabled = true;
        button.classList.add("is-loading");
        button.setAttribute("aria-busy", "true");
        buttonText.textContent = "Connecting to secure checkout…";
    });
})();
