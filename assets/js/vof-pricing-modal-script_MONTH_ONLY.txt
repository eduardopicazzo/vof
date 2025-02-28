// File: vof-pricing-modal-script.js
console.log('VOF Debug: Pricing Modal script loaded');

const VOF_MODAL_CONFIG = {
    enableFallback: false
};

// Global state with fallback data
const defaultState = {
    isMultiPricingOn: false,
    isApiData: false,
    tiers: [
        {
            name: "biz",
            description: "Ideal para emprendedores, agencias y freelancers que buscan construir su presencia local con fuerza. biz te permite publicar en la mayoría de categorías a excepción de autos e inmuebles.",
            price: 349,
            features: [
                "Acceso como vendedor a noisemarkets marketplace y sus 7 beneficios",
                "8 listados/mes",
                "Publica en la mayoría de categorías excepto autos e inmuebles",
                "2 destacadores Top/mes",
                "3 destacadores BumpUp/mes",
                "2 destacadores Destacados/mes"
            ],
            isRecommended: true,
            isGrayOut: false
        },
        {
            name: "noise",
            description: "Perfecto para agentes inmobiliarios, vendedores de autos y profesionales que buscan máxima visibilidad local y conectar con su comunidad con toda flexibilidad.",
            price: 549,
            features: [
                "Acceso como vendedor a noisemarkets marketplace y sus 7 beneficios",
                "16 Listados/mes",
                "Publica en todas las categorías",
                "5 destacadores Top/mes",
                "3 destacadores BumpUp/mes",
                "2 destacadores Destacados/mes"
            ],
            isRecommended: false,
            isGrayOut: false
        },
        {
            name: "noise+",
            description: "Con Landing Page incluida, noise+ te da máxima flexibilidad para conectar con tus mejores clientes y llevarles tu propuesta al siguiente nivel.",
            price: 1567,
            features: [
                "Acceso como vendedor a noisemarkets marketplace y sus 7 beneficios",
                "30 listados/mes",
                "Publica en todas las categorías",
                "10 destacadores Top/mes",
                "6 destacadores BumpUp/mes",
                "6 destacadores Destacados/mes"
            ],
            isRecommended: false,
            isGrayOut: false
        }
    ]
};

let modalState = { ...defaultState };

function sanitizeId(input) {
    return input
      .toLowerCase() // Convert to lowercase
      .replace(/\s+/g, '_') // Replace whitespace with underscores
      .replace(/\+/g, '_plus') // Replace "+" with "_plus"
      .replace(/-/g, '_') // Replace "-" with "_"
      .replace(/[\[\]]/g, ''); // Remove "[" and "]"
  }

// Helper Functions
function createTierElement(tier, index) {
    const tierElement = document.createElement('div');
    tierElement.className = `vof-pm-tier ${tier.isRecommended ? 'vof-pm-recommended' : ''} ${tier.isGrayOut ? 'vof-pm-gray-out' : ''}`;
    
    // Store the tier's data for the click handler
    // const subscribeBtnId = `vof-pm-subscribe-${tier.name.toLowerCase().replace('+', '_plus')}`;
    const subscribeBtnId = `vof-pm-subscribe-${sanitizeId(tier.name)}`;
    
    tierElement.innerHTML = `
        <div class="vof-pm-tier-header">
            ${tier.isRecommended && !tier.isGrayOut ? '<div class="vof-pm-recommended-badge">Recomendada</div>' : ''}
            <h3 class="vof-pm-tier-name">${tier.name}</h3>
            <p class="vof-pm-tier-description">${tier.description}</p>
        </div>
        <div class="vof-pm-tier-price">
            MXN ${tier.price} <span>por mes</span>
        </div>
        <button 
            id="${subscribeBtnId}"
            class="vof-pm-btn ${tier.isGrayOut ? 'vof-pm-btn-disabled' : 'vof-pm-btn-primary'}" 
            ${tier.isGrayOut ? 'disabled' : ''}
            data-tier-name="${tier.name}"
            data-tier-price="${tier.price}"
        >
            <span>${tier.isGrayOut ? 'No disponible' : `Continuar con ${tier.name}`}</span>
        </button>
        <div class="vof-pm-tier-features">
            <h4>Esto incluye:</h4>
            <ul class="vof-pm-feature-list">
                ${tier.features.map(feature => `<li>${feature}</li>`).join('')}
            </ul>
        </div>
    `;
    
    // Add button click handler after the element is created
    setTimeout(() => {
        const subscribeBtn = tierElement.querySelector(`#${subscribeBtnId}`);
        if (subscribeBtn && !tier.isGrayOut) {
            subscribeBtn.addEventListener('click', () => {
                console.log('VOF Debug: Tier button clicked:', {
                    name: subscribeBtn.getAttribute('data-tier-name'),
                    price: subscribeBtn.getAttribute('data-tier-price')
                });
                handleTierSelection(
                    subscribeBtn.getAttribute('data-tier-name'),
                    parseFloat(subscribeBtn.getAttribute('data-tier-price'))
                );
            });
        }
        tierElement.classList.add('vof-pm-fade-in');
    }, index * 100);
    
    return tierElement;
}

function handleTierSelection(tierName, tierPrice) {
    console.log('VOF Debug: Tier selected:', tierName, 'Price:', tierPrice);
    console.log('VOF Debug: Modal state:', modalState);
    console.log('VOF Debug: handleTierSelection called with:', {
        tierName,
        tierPrice,
        modalState: modalState
    });

    // Validate we have customer data
    if (!modalState.customer_meta?.uuid) {
        console.error('VOF Debug: No UUID found for customer');
        return;
    }

    // Validate checkout handler exists
    if (!window.handleCheckoutStart) {
        console.error('VOF Debug: handleCheckoutStart not found');
        return;
    }


    // Get VOF handler instance (from orchestrator)
    if (window.handleCheckoutStart) {
        window.handleCheckoutStart({
            uuid: modalState.customer_meta.uuid,
            tier_selected: {
                name: tierName.replace('+', '_plus'),
                price: tierPrice
            }
        });
    } else {
        console.error('VOF Debug: Checkout handler not found');
    }
}

function renderTabs() {
    const tabsContainer = document.getElementById('vof-pm-tabsContainer');
    if (!tabsContainer) return;

    if (modalState.isMultiPricingOn) {
        tabsContainer.classList.remove('vof-pm-single-tab');
        tabsContainer.innerHTML = `
            <button class="vof-pm-tab-btn vof-pm-active" data-tab="monthly">Mensualmente</button>
            <button class="vof-pm-tab-btn" data-tab="yearly">Anualmente</button>
        `;
    } else {
        tabsContainer.classList.add('vof-pm-single-tab');
        tabsContainer.innerHTML = `
            <button class="vof-pm-tab-btn vof-pm-active" data-tab="monthly">Mensualmente</button>
        `;
    }

    const newTabBtns = document.querySelectorAll('.vof-pm-tab-btn');
    newTabBtns.forEach(btn => {
        btn.addEventListener('click', switchTab);
    });
}

function renderTiers() {
    const tierContainer = document.querySelector('.vof-pm-tier-container');
    if (!tierContainer) return;

    tierContainer.innerHTML = '';
    modalState.tiers.forEach((tier, index) => {
        const tierElement = createTierElement(tier, index);
        tierContainer.appendChild(tierElement);
    });
}

function switchTab(event) {
    const tabName = event.target.getAttribute('data-tab');
    
    const currentTabBtns = document.querySelectorAll('.vof-pm-tab-btn');
    const currentTabContents = document.querySelectorAll('.vof-pm-tab-content');
    
    currentTabBtns.forEach(btn => btn.classList.remove('vof-pm-active'));
    currentTabContents.forEach(content => content.classList.remove('vof-pm-active'));
    
    event.target.classList.add('vof-pm-active');
    document.getElementById(`vof-pm-${tabName}Content`).classList.add('vof-pm-active');
}

// Core Modal Functions
function updateModalState(response) {
    console.log('VOF Debug: Updating modal state with response:', response);

    try {
        if (response && response.pricing_data) {
            // Extract pricing data from API response
            const { is_multi_pricing_on, tiers } = response.pricing_data;
            
            modalState = {
                isMultiPricingOn: is_multi_pricing_on,
                isApiData: true,
                tiers: tiers,
                customer_meta: response.customer_meta // Make sure this exists
            };
            console.log('VOF Debug: Updated modal state:', modalState);
        } else {
            console.warn('VOF Debug: Invalid API response format, using fallback data');
            modalState = { ...defaultState };
        }

        renderTabs();
        renderTiers();
    } catch (error) {
        console.error('VOF Debug: Error updating modal state:', error);
        modalState = { ...defaultState };
        renderTabs();
        renderTiers();
    }
}

function closeModal() {
    const modal = document.getElementById('vof-pm-pricingModal');
    if (!modal) return;

    modal.classList.remove('vof-pm-open');
    document.body.style.overflow = 'auto';
}

function openModal(apiData = null) {
    console.log('VOF Debug: Opening modal with data:', apiData);

    const modal = document.getElementById('vof-pm-pricingModal');
    if (!modal) {
        console.error('VOF Debug: Modal element not found');
        return;
    }

    // Update state based on API data or use fallback
    if (apiData) { // TODO: ENSURE DATA IS COMPLETE AND CORRECT STRUCTURE 
        updateModalState(apiData);
    } else if (VOF_MODAL_CONFIG.enableFallback) {
        console.log('VOF Debug: Using fallback data');
        modalState = { ...defaultState };
        renderTabs();
        renderTiers();
    } else {
        console.error('VOF Debug: No API data provided and fallback disabled');
        return; // Don't open modal if no data and fallback disabled
    }
    
    modal.classList.add('vof-pm-open');
    document.body.style.overflow = 'hidden';
}

// Initialize event listeners
document.addEventListener('DOMContentLoaded', function() {
    const modalPreventClose = document.querySelector('.vof-pm-modal');
    const modal = document.getElementById('vof-pm-pricingModal');
    const closeModalBtn = document.getElementById('vof-pm-closeModalBtn');
    const cancelBtn = document.getElementById('vof-pm-cancelBtn');
    const contactSalesBtn = document.getElementById('vof-pm-contactSalesBtn');

    // Prevent closing modal when clicking inside
    if (modalPreventClose) {
        modalPreventClose.addEventListener('click', (event) => {
            event.stopPropagation();
        });
    }

    // Add event listeners if elements exist
    if (closeModalBtn) closeModalBtn.addEventListener('click', closeModal);
    if (cancelBtn) cancelBtn.addEventListener('click', closeModal);
    if (contactSalesBtn) {
        contactSalesBtn.addEventListener('click', () => {
            alert('Contactando a ventas...');
            closeModal();
        });
    }

    // Close on outside click
    if (modal) {
        window.addEventListener('click', (event) => {
            if (event.target === modal) {
                closeModal();
            }
        });
    }
});

// Expose functions to window object for orchestrator
window.openModal = openModal;
window.updateModalState = updateModalState;