
(() => {
    "use strict";

    const form = document.getElementById("ticketSelectionForm");
    if (!form) return;

    const totalDisplay = document.getElementById("ticketSelectionTotal");
    const message = document.getElementById("ticketSelectionMessage");
    const submitButton = document.getElementById("continueToCheckout");
    const quantityInputs = Array.from(
        form.querySelectorAll("[data-ticket-quantity]")
    );

    let isSubmitting = false;

    const formatXof = (amount) =>
        `${new Intl.NumberFormat("fr-FR", {
            maximumFractionDigits: 0
        }).format(amount)} XOF`;

    function calculateSelection() {
        let total = 0;
        let quantityTotal = 0;

        quantityInputs.forEach((input) => {
            const price = Number(input.dataset.price);
            const quantity = Number(input.value);

            if (!Number.isFinite(price) || !Number.isFinite(quantity)) return;

            total += price * quantity;
            quantityTotal += quantity;
        });

        return { total, quantityTotal };
    }

    function updateTotal() {
        const { total, quantityTotal } = calculateSelection();

        totalDisplay.textContent = formatXof(total);

        submitButton.disabled = isSubmitting || quantityTotal < 1;
        return quantityTotal;
    }

    function setQuantity(input, value) {
        const min = Number(input.min) || 0;
        const max = Number(input.max) || 10;

        input.value = String(
            Math.max(min, Math.min(Math.trunc(value), max))
        );
    }

    form.querySelectorAll(".quantity-btn").forEach((button) => {
        button.addEventListener("click", () => {
            const row = button.closest(".ticket-option");
            const input = row?.querySelector("[data-ticket-quantity]");

            if (!input || input.disabled || isSubmitting) return;

            const current = Number(input.value) || 0;

            if (button.dataset.action === "increase") {
                setQuantity(input, current + 1);
            } else if (button.dataset.action === "decrease") {
                setQuantity(input, current - 1);
            }

            message.textContent = "";
            updateTotal();
        });
    });

    quantityInputs.forEach((input) => {
        input.addEventListener("change", () => {
            const value = Number.parseInt(input.value, 10);

            setQuantity(input, Number.isFinite(value) ? value : 0);
            message.textContent = "";
            updateTotal();
        });
    });

    form.addEventListener("submit", (event) => {
        if (isSubmitting) {
            event.preventDefault();
            return;
        }

        const selectedCount = updateTotal();

        if (selectedCount < 1) {
            event.preventDefault();
            message.textContent = "Please select at least one ticket.";
            return;
        }

        for (const input of quantityInputs) {
            const quantity = Number(input.value);
            const max = Number(input.max);

            if (
                !Number.isInteger(quantity) ||
                quantity < 0 ||
                quantity > max
            ) {
                event.preventDefault();
                message.textContent = "Please check your ticket quantities.";
                input.focus();
                return;
            }
        }

        if (!form.checkValidity()) {
            event.preventDefault();
            form.reportValidity();
            return;
        }

        isSubmitting = true;
        submitButton.disabled = true;
        submitButton.textContent = "Preparing checkout…";

        // The browser POSTs to start-checkout.php.
        // That endpoint validates the selection and redirects to checkout.php.
    });

    updateTotal();
})();
