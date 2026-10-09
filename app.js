const themeSelect = document.querySelector('#theme-select');
const supportedThemes = ['azul-niebla', 'verde-salvia', 'gris-mineral', 'oscuro'];
const themeStorageKey = 'ferreteria-color-theme';

function applyTheme(theme, persist = false) {
    const selectedTheme = supportedThemes.includes(theme) ? theme : 'azul-niebla';
    document.documentElement.dataset.theme = selectedTheme;
    if (themeSelect) {
        themeSelect.value = selectedTheme;
    }

    const themeColor = getComputedStyle(document.documentElement).getPropertyValue('--header-background').trim();
    document.querySelector('meta[name="theme-color"]')?.setAttribute('content', themeColor);

    if (persist) {
        try {
            localStorage.setItem(themeStorageKey, selectedTheme);
        } catch (error) {
            return;
        }
    }
}

if (themeSelect) {
    let savedTheme = 'azul-niebla';
    try {
        savedTheme = localStorage.getItem(themeStorageKey) ?? savedTheme;
    } catch (error) {
        savedTheme = 'azul-niebla';
    }
    applyTheme(savedTheme);
    themeSelect.addEventListener('change', () => applyTheme(themeSelect.value, true));
}

const productCatalogNode = document.querySelector('#product-catalog');
const productSearch = document.querySelector('#producto-busqueda');
const productSuggestions = document.querySelector('#product-suggestions');
const productFeedback = document.querySelector('#producto-resultados');
const productQuantity = document.querySelector('#cart-quantity');
const addCartItemButton = document.querySelector('#add-cart-item');
const cartItemsList = document.querySelector('#cart-items');
const cartEmptyState = document.querySelector('#cart-empty');
const cartCount = document.querySelector('#cart-count');
const cartTotal = document.querySelector('#cart-total');
const cartHiddenInputs = document.querySelector('#cart-hidden-inputs');
const completeSaleButton = document.querySelector('#complete-sale');
const saleForm = document.querySelector('.sale-form');

if (productCatalogNode && productSearch && productSuggestions && productFeedback && productQuantity && addCartItemButton && cartItemsList && cartEmptyState && cartCount && cartTotal && cartHiddenInputs && completeSaleButton && saleForm) {
    const products = JSON.parse(productCatalogNode.textContent);
    const cart = new Map();
    const currency = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });
    let selectedProduct = null;

    function remainingStock(product) {
        return product.stock - (cart.get(product.id)?.quantity ?? 0);
    }

    function closeSuggestions() {
        productSuggestions.hidden = true;
        productSearch.setAttribute('aria-expanded', 'false');
    }

    function renderSuggestions() {
        const query = productSearch.value.trim().toLocaleLowerCase('es');
        productSuggestions.replaceChildren();

        if (!query) {
            closeSuggestions();
            productFeedback.textContent = '';
            return;
        }

        const matches = products.filter((product) => {
            const canAdd = remainingStock(product) > 0 && (cart.size < 10 || cart.has(product.id));
            return canAdd && product.nombre.toLocaleLowerCase('es').includes(query);
        }).slice(0, 8);

        if (!matches.length) {
            closeSuggestions();
            productFeedback.textContent = cart.size >= 10
                ? 'El carrito admite hasta 10 productos diferentes.'
                : 'No hay coincidencias con existencias disponibles.';
            return;
        }

        matches.forEach((product, index) => {
            const option = document.createElement('button');
            option.type = 'button';
            option.id = `product-option-${product.id}`;
            option.className = 'product-suggestion';
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', 'false');
            option.innerHTML = `<span class="suggestion-name"></span><span class="suggestion-detail"></span>`;
            option.querySelector('.suggestion-name').textContent = product.nombre;
            option.querySelector('.suggestion-detail').textContent = `${currency.format(product.precio)} · ${remainingStock(product)} disponibles`;
            option.addEventListener('click', () => {
                selectedProduct = product;
                productSearch.value = product.nombre;
                productSearch.setAttribute('aria-activedescendant', option.id);
                productQuantity.max = String(remainingStock(product));
                productQuantity.value = '1';
                addCartItemButton.disabled = false;
                productFeedback.textContent = `${remainingStock(product)} unidades disponibles.`;
                closeSuggestions();
                productQuantity.focus();
            });
            productSuggestions.append(option);
        });

        productFeedback.textContent = `${matches.length} ${matches.length === 1 ? 'coincidencia' : 'coincidencias'}`;
        productSuggestions.hidden = false;
        productSearch.setAttribute('aria-expanded', 'true');
    }

    function renderCart() {
        cartItemsList.replaceChildren();
        cartHiddenInputs.replaceChildren();
        let total = 0;
        let itemIndex = 0;

        cart.forEach((item, productId) => {
            const lineTotal = item.product.precio * item.quantity;
            total += lineTotal;

            const row = document.createElement('li');
            row.className = 'cart-item';
            const details = document.createElement('div');
            details.className = 'cart-item-details';
            const name = document.createElement('strong');
            name.textContent = item.product.nombre;
            const quantity = document.createElement('span');
            quantity.textContent = `${item.quantity} × ${currency.format(item.product.precio)}`;
            details.append(name, quantity);

            const amount = document.createElement('strong');
            amount.className = 'cart-item-total';
            amount.textContent = currency.format(lineTotal);

            const removeButton = document.createElement('button');
            removeButton.type = 'button';
            removeButton.className = 'cart-remove-button';
            removeButton.textContent = 'Quitar';
            removeButton.setAttribute('aria-label', `Quitar ${item.product.nombre} del carrito`);
            removeButton.addEventListener('click', () => {
                cart.delete(productId);
                renderCart();
                renderSuggestions();
            });
            row.append(details, amount, removeButton);
            cartItemsList.append(row);

            const productInput = document.createElement('input');
            productInput.type = 'hidden';
            productInput.name = `items[${itemIndex}][id_producto]`;
            productInput.value = String(productId);
            const quantityInput = document.createElement('input');
            quantityInput.type = 'hidden';
            quantityInput.name = `items[${itemIndex}][cantidad]`;
            quantityInput.value = String(item.quantity);
            cartHiddenInputs.append(productInput, quantityInput);
            itemIndex += 1;
        });

        cartCount.textContent = `${cart.size} ${cart.size === 1 ? 'producto' : 'productos'}`;
        cartEmptyState.hidden = cart.size > 0;
        cartTotal.textContent = currency.format(total);
        completeSaleButton.disabled = cart.size === 0;
    }

    productSearch.addEventListener('input', () => {
        selectedProduct = null;
        addCartItemButton.disabled = true;
        productSearch.removeAttribute('aria-activedescendant');
        renderSuggestions();
    });

    productSearch.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeSuggestions();
        } else if (event.key === 'ArrowDown') {
            const firstSuggestion = productSuggestions.querySelector('.product-suggestion');
            if (firstSuggestion) {
                event.preventDefault();
                firstSuggestion.focus();
            }
        } else if (event.key === 'Enter') {
            event.preventDefault();
            if (selectedProduct) {
                addCartItemButton.click();
            } else {
                productSuggestions.querySelector('.product-suggestion')?.click();
            }
        }
    });

    addCartItemButton.addEventListener('click', () => {
        if (!selectedProduct) {
            return;
        }
        const quantity = Number.parseInt(productQuantity.value, 10);
        const alreadyAdded = cart.get(selectedProduct.id)?.quantity ?? 0;
        if (!Number.isInteger(quantity) || quantity < 1 || quantity > remainingStock(selectedProduct)) {
            productFeedback.textContent = `La cantidad debe ser de 1 a ${remainingStock(selectedProduct)} unidades.`;
            return;
        }
        if (!cart.has(selectedProduct.id) && cart.size >= 10) {
            productFeedback.textContent = 'El carrito admite hasta 10 productos diferentes.';
            return;
        }

        cart.set(selectedProduct.id, {
            product: selectedProduct,
            quantity: alreadyAdded + quantity,
        });
        productFeedback.textContent = `${selectedProduct.nombre} agregado.`;
        productSearch.value = '';
        productQuantity.value = '1';
        selectedProduct = null;
        addCartItemButton.disabled = true;
        productSearch.focus();
        renderCart();
        renderSuggestions();
    });

    saleForm.addEventListener('submit', (event) => {
        if (!cart.size) {
            event.preventDefault();
        }
    });

    renderCart();
}