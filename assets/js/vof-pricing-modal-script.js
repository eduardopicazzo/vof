// At the top of your modal script
console.log('VOF Debug: Modal script loaded');

// Global state
let modalState = {
    isMultiPricingOn: false,
    isApiData: false,
    tiers: [
        {
            name: "biz",
            description: "Ideal para emprendedores, agencias y freelancers que buscan construir su presencia local con fuerza. biz te permite publicar en la mayoría de categorías a excepción de autos e inmuebles.",
            price: 349,
            features: [
                "Acceso como vendedor a noisemarkets marketplace y sus 7 beneficios",
                "8 listados /mes",
                "Publica en la mayoría de categorías excepto autos e inmuebles",
                "2 destacadores Top /mes",
                "3 destacadores BumpUp /mes",
                "2 destacadores Destacados /mes"
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
                "16 Listados /mes",
                "Publica en todas las categorías",
                "5 destacadores Top /mes",
                "3 destacadores BumpUp /mes",
                "2 destacadores Destacados /mes"
            ],
            isRecommended: true,
            isGrayOut: true
        },
        {
            name: "noise+",
            description: "Con Landing Page incluida, noise+ te da máxima flexibilidad para conectar con tus mejores clientes y llevarles tu propuesta al siguiente nivel.",
            price: 1567,
            features: [
                "Acceso como vendedor a noisemarkets marketplace y sus 7 beneficios",
                "30 listados /mes",
                "Publica en todas las categorías",
                "10 destacadores Top /mes",
                "6 destacadores BumpUp /mes",
                "6 destacadores Destacados /mes"
            ],
            isRecommended: false,
            isGrayOut: true
        }
    ]
};

// Global function declarations
function createTierElement(tier, index) {
    const tierElement = document.createElement('div');
    tierElement.className = `tier ${tier.isRecommended ? 'recommended' : ''} ${tier.isGrayOut ? 'gray-out' : ''}`;
    
    tierElement.innerHTML = `
        <div class="tier-header">
            ${tier.isRecommended && !tier.isGrayOut ? '<div class="recommended-badge">Recomendada</div>' : ''}
            <h3 class="tier-name">${tier.name}</h3>
            <p class="tier-description">${tier.description}</p>
        </div>
        <div class="tier-price">
            MXN ${tier.price} <span>por mes</span>
        </div>
        <button class="btn ${tier.isGrayOut ? 'btn-disabled' : 'btn-primary'}" ${tier.isGrayOut ? 'disabled' : ''}>
            <span>${tier.isGrayOut ? 'No disponible' : 'Suscribirse'}</span>
        </button>
        <div class="tier-features">
            <h4>Esto incluye:</h4>
            <ul class="feature-list">
                ${tier.features.map(feature => `<li>${feature}</li>`).join('')}
            </ul>
        </div>
    `;
    
    setTimeout(() => {
        tierElement.classList.add('fade-in');
    }, index * 100);
    
    return tierElement;
}

function renderTabs() {
    const tabsContainer = document.getElementById('tabsContainer');
    if (!tabsContainer) return;

    if (modalState.isMultiPricingOn) {
        tabsContainer.classList.remove('single-tab');
        tabsContainer.innerHTML = `
            <button class="tab-btn active" data-tab="monthly">Mensualmente</button>
            <button class="tab-btn" data-tab="yearly">Anualmente</button>
        `;
    } else {
        tabsContainer.classList.add('single-tab');
        tabsContainer.innerHTML = `
            <button class="tab-btn active" data-tab="monthly">Mensualmente</button>
        `;
    }

    const newTabBtns = document.querySelectorAll('.tab-btn');
    newTabBtns.forEach(btn => {
        btn.addEventListener('click', switchTab);
    });
}

function renderTiers() {
    const tierContainer = document.querySelector('.tier-container');
    if (!tierContainer) return;

    tierContainer.innerHTML = '';
    modalState.tiers.forEach((tier, index) => {
        const tierElement = createTierElement(tier, index);
        tierContainer.appendChild(tierElement);
    });
}

function switchTab(event) {
    const tabName = event.target.getAttribute('data-tab');
    
    const currentTabBtns = document.querySelectorAll('.tab-btn');
    const currentTabContents = document.querySelectorAll('.tab-content');
    
    currentTabBtns.forEach(btn => btn.classList.remove('active'));
    currentTabContents.forEach(content => content.classList.remove('active'));
    
    event.target.classList.add('active');
    document.getElementById(`${tabName}Content`).classList.add('active');
}

function updateModalState(newState) {
    modalState = { ...modalState, ...newState, isApiData: true };
    renderTabs();
    renderTiers();
}

function closeModal() {
    const modal = document.getElementById('pricingModal');
    if (!modal) return;

    modal.classList.remove('open');
    document.body.style.overflow = 'auto';
}

function openModal(useApiData = false) {
    // comment debug later
    console.log('VOF Debug: openModal called with:', useApiData);

    const modal = document.getElementById('pricingModal');
    
    // comment debug later
    console.log('VOF Debug: Modal element:', modal);


    if (!modal) {
        console.error('Modal element not found');
        return;
    }
    
    if (!useApiData) {
        // Reset to original state
        modalState = {
            isMultiPricingOn: false,
            isApiData: false,
            tiers: [
                {
                    name: "biz",
                    description: "Ideal para emprendedores, agencias y freelancers que buscan construir su presencia local con fuerza. biz te permite publicar en la mayoría de categorías a excepción de autos e inmuebles.",
                    price: 349,
                    features: [
                        "Acceso como vendedor a noisemarkets marketplace y sus 7 beneficios",
                        "8 listados /mes",
                        "Publica en la mayoría de categorías excepto autos e inmuebles",
                        "2 destacadores Top /mes",
                        "3 destacadores BumpUp /mes",
                        "2 destacadores Destacados /mes"
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
                        "16 Listados /mes",
                        "Publica en todas las categorías",
                        "5 destacadores Top /mes",
                        "3 destacadores BumpUp /mes",
                        "2 destacadores Destacados /mes"
                    ],
                    isRecommended: true,
                    isGrayOut: true
                },
                {
                    name: "noise+",
                    description: "Con Landing Page incluida, noise+ te da máxima flexibilidad para conectar con tus mejores clientes y llevarles tu propuesta al siguiente nivel.",
                    price: 1567,
                    features: [
                        "Acceso como vendedor a noisemarkets marketplace y sus 7 beneficios",
                        "30 listados /mes",
                        "Publica en todas las categorías",
                        "10 destacadores Top /mes",
                        "6 destacadores BumpUp /mes",
                        "6 destacadores Destacados /mes"
                    ],
                    isRecommended: false,
                    isGrayOut: true
                }
            ]
        };
    }
    
    modal.classList.add('open');
    document.body.style.overflow = 'hidden';
    renderTabs();
    renderTiers();
}

// Expose functions to window object
window.openModal = openModal;
window.updateModalState = updateModalState;

// Initialize event listeners when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('pricingModal');
    const openModalBtn = document.getElementById('openModalBtn');
    const closeModalBtn = document.getElementById('closeModalBtn');
    const cancelBtn = document.getElementById('cancelBtn');
    const contactSalesBtn = document.getElementById('contactSalesBtn');

    // Add event listeners if elements exist
    if (openModalBtn) openModalBtn.addEventListener('click', () => openModal(false));
    if (closeModalBtn) closeModalBtn.addEventListener('click', closeModal);
    if (cancelBtn) cancelBtn.addEventListener('click', closeModal);
    if (contactSalesBtn) {
        contactSalesBtn.addEventListener('click', () => {
            alert('Contactando a ventas...');
            closeModal();
        });
    }

    // Close on outside click
    window.addEventListener('click', (event) => {
        if (event.target === modal) {
            closeModal();
        }
    });
});