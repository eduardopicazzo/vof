// File: vof-pricing-modal-script.js This script handles the modal logic for both monthly and yearly pricing schemes.
console.log('VOF Debug: Pricing Modal script loaded');

const VOF_MODAL_CONFIG = {
    enableFallback: true // Enable fallback if admin settings aren't available
};

// Global state with fallback data updated to include both monthly and yearly pricing options
const defaultState = {
    isMultiPricingOn: true,
    isApiData: false,
    monthlyTiers: [
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
    ],
    yearlyTiers: [
        {
            name: "biz-yearly",
            description: "Ideal para emprendedores, agencias y freelancers que buscan construir su presencia local con fuerza. biz te permite publicar en la mayoría de categorías a excepción de autos e inmuebles.",
            price: 3490, // 10 months price (2 months free)
            interval: "year",
            features: [
                "Acceso como vendedor a noisemarkets marketplace y sus 7 beneficios",
                "8 listados/mes",
                "Publica en la mayoría de categorías excepto autos e inmuebles",
                "2 destacadores Top/mes",
                "3 destacadores BumpUp/mes",
                "2 destacadores Destacados/mes",
                "2 meses gratis"
            ],
            isRecommended: true,
            isGrayOut: false
        },
        {
            name: "noise-yearly",
            description: "Perfecto para agentes inmobiliarios, vendedores de autos y profesionales que buscan máxima visibilidad local y conectar con su comunidad con toda flexibilidad.",
            price: 5490, // 10 months price (2 months free)
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
            name: "noise+-yearly",
            description: "Con Landing Page incluida, noise+ te da máxima flexibilidad para conectar con tus mejores clientes y llevarles tu propuesta al siguiente nivel.",
            price: 15670, // 10 months price (2 months free) 
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

// Check if admin dashboard settings are available
let adminSettings = {};
if (typeof vofPricingModalConfig !== 'undefined') {
    console.log('VOF Debug: Admin settings loaded:', vofPricingModalConfig);
    console.log('VOF Debug: Currency code from PHP:', vofPricingModalConfig.iso_currency_code);
    adminSettings = vofPricingModalConfig;
}

// Update modalState structure to support both pricing schemes
// Use admin settings if available, otherwise use defaults
let modalState = {
    isMultiPricingOn: adminSettings.is_multi_pricing_on !== undefined ? 
        adminSettings.is_multi_pricing_on : defaultState.isMultiPricingOn,
    isApiData: false,
    isoCurrencyCode: adminSettings.iso_currency_code || 'USD', // Default to USD if not set
    pricingModalTitle: adminSettings.pricing_modal_title || 'Select Your Plan',
    tab_label_monthly: adminSettings.tab_label_monthly || 'Monthly Plans',
    tab_label_yearly: adminSettings.tab_label_yearly || 'Yearly Plans',
    monthlyTiers: adminSettings.monthly_tiers && adminSettings.monthly_tiers.length ? 
        adminSettings.monthly_tiers.map(tier => ({ 
            ...tier, 
            stripePriceIdTest: tier.stripePriceIdTest || '',
            stripePriceIdLive: tier.stripePriceIdLive || '',
            stripeLookupKeyTest: tier.stripeLookupKeyTest || '',
            stripeLookupKeyLive: tier.stripeLookupKeyLive || ''
        })) : [...defaultState.monthlyTiers],
    yearlyTiers: adminSettings.yearly_tiers && adminSettings.yearly_tiers.length ? 
        adminSettings.yearly_tiers.map(tier => ({
            ...tier,
            stripePriceIdTest: tier.stripePriceIdTest || '',
            stripePriceIdLive: tier.stripePriceIdLive || '',
            stripeLookupKeyTest: tier.stripeLookupKeyTest || '',
            stripeLookupKeyLive: tier.stripeLookupKeyLive || ''
        })): [...defaultState.yearlyTiers],
    selectedInterval: "month", // Default to monthly pricing scheme
}

function sanitizeId(input) {
    return input
      .toLowerCase() // Convert to lowercase
      .replace(/\s+/g, '_') // Replace whitespace with underscores
      .replace(/\+/g, '_plus') // Replace "+" with "_plus"
      .replace(/-/g, '_') // Replace "-" with "_"
      .replace(/[\[\]]/g, ''); // Remove "[" and "]"
  }

// Update createTierElement function to include interval data
function createTierElement(tier, index) {
    const tierElement = document.createElement('div');
    tierElement.className = `vof-pm-tier ${tier.isRecommended ? 'vof-pm-recommended' : ''} ${tier.isGrayOut ? 'vof-pm-gray-out' : ''}`;
    
    // Store the tier's data for the click handler
    const subscribeBtnId = `vof-pm-subscribe-${sanitizeId(tier.name)}`;
    
    // Use the custom billing cycle label if provided, otherwise use default interval text
    let intervalText = tier.interval === "year" ? "por año" : "por mes";
    if (tier.billingCycleLabel && tier.billingCycleLabel.trim() !== '') {
        intervalText = tier.billingCycleLabel;
    }
    
    // Get currency code from modalState or fallback to USD
    const currencyCode = modalState.isoCurrencyCode || 'USD';
    console.log('VOF Debug: Using currency code:', currencyCode);
    
    tierElement.innerHTML = `
        <div class="vof-pm-tier-header">
            ${tier.isRecommended && !tier.isGrayOut ? '<div class="vof-pm-recommended-badge">Recomendada</div>' : ''}
            <h3 class="vof-pm-tier-name">${tier.name}</h3>
            <p class="vof-pm-tier-description">${tier.description}</p>
        </div>
        <div class="vof-pm-tier-price">
            ${currencyCode} $${tier.price} <span>${intervalText}</span>
        </div>
        <button 
            id="${subscribeBtnId}"
            class="vof-pm-btn ${tier.isGrayOut ? 'vof-pm-btn-disabled' : 'vof-pm-btn-primary'}" 
            ${tier.isGrayOut ? 'disabled' : ''}
            data-tier-name="${tier.name}"
            data-tier-price="${tier.price}"
            data-tier-interval="${tier.interval || 'month'}"
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
    
    // Update button click handler to include interval information
    setTimeout(() => {
        const subscribeBtn = tierElement.querySelector(`#${subscribeBtnId}`);
        if (subscribeBtn && !tier.isGrayOut) {
            subscribeBtn.addEventListener('click', () => {
                console.log('VOF Debug: Tier button clicked:', {
                    name: subscribeBtn.getAttribute('data-tier-name'),
                    price: subscribeBtn.getAttribute('data-tier-price'),
                    interval: subscribeBtn.getAttribute('data-tier-interval')
                });
                handleTierSelection(tier); // pass all the tier data, instead of just some elements of the tier
            });
        }
        tierElement.classList.add('vof-pm-fade-in');
    }, index * 100);
    
    return tierElement;
}

// Update handleTierSelection to include interval
// function handleTierSelection_OLD(tierName, tierPrice, tierInterval) {
//     console.log('VOF Debug: Tier selected:', tierName, 'Price:', tierPrice, 'Interval:', tierInterval);
//     console.log('VOF Debug: Modal state:', modalState);
//     console.log('VOF Debug: handleTierSelection called with:', {
//         tierName,
//         tierPrice,
//         tierInterval,
//         modalState: modalState
//     });

//     // Validate we have customer data
//     if (!modalState.customer_meta?.uuid) { // array from the post listing api response
//         console.error('VOF Debug: No UUID found for customer');
//         return;
//     }

//     // Validate checkout handler exists
//     if (!window.handleCheckoutStart) {
//         console.error('VOF Debug: handleCheckoutStart not found');
//         return;
//     }

//     // Get VOF handler instance (from orchestrator)
//     if (window.handleCheckoutStart) {
//         window.handleCheckoutStart({
//             uuid: modalState.customer_meta.uuid,
//             tier_selected: {
//                 name: tierName.replace('+', '_plus'),
//                 price: tierPrice,
//                 interval: tierInterval || 'month',
//                 // stripe_lookup_key: 'stripe_lookup_key', // pass later when admin dashboard ready
//                 // stripe_price_id: 'stripe_price_id'      // pass later when admin dashboard ready
//             }
//         });
//     } else {
//         console.error('VOF Debug: Checkout handler not found');
//     }
// }
function handleTierSelection(tier) {
    console.log('VOF Debug: Tier selected:', tier);

    // Validate we have customer data
    if (!modalState.customer_meta?.uuid) { // array from the post listing api response
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
                // name: tier.name.replace('+', '_plus'),
                name: tier.name,
                price: tier.price,
                interval: tier.interval || 'month',
                stripePriceIdTest: tier.stripePriceIdTest || '',
                stripePriceIdLive:  tier.stripePriceIdLive || '',
                stripeLookupKeyTest: tier.stripeLookupKeyTest || '',
                stripeLookupKeyLive: tier.stripeLookupKeyLive || ''
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
        const monthlyLabel = modalState.tab_label_monthly || 'Monthly Plans';
        const yearlyLabel = modalState.tab_label_yearly || 'Yearly Plans';
        tabsContainer.innerHTML = `
            <button class="vof-pm-tab-btn vof-pm-active" data-tab="monthly">${monthlyLabel}</button>
            <button class="vof-pm-tab-btn" data-tab="yearly">${yearlyLabel}</button>
        `;

        // Ensure yearly content has the proper structure
        const yearlyContent = document.getElementById('vof-pm-yearlyContent');
        if (yearlyContent && modalState.yearlyTiers && modalState.yearlyTiers.length > 0 ) {
            yearlyContent.innerHTML = `
                <div class="vof-pm-tier-container yearly-container">
                    <!-- Yearly tiers will be rendered here -->
                </div>
            `;
        }
    } else {
        tabsContainer.classList.add('vof-pm-single-tab');
        const monthlyLabel = modalState.tab_label_monthly || 'Monthly Plans';
        tabsContainer.innerHTML = `
            <button class="vof-pm-tab-btn vof-pm-active" data-tab="monthly">${monthlyLabel}</button>
        `;
    }

    const newTabBtns = document.querySelectorAll('.vof-pm-tab-btn');
    newTabBtns.forEach(btn => {
        btn.addEventListener('click', switchTab);
    });

    // Initialize both containers
    const monthlyContainer = document.querySelector('#vof-pm-monthlyContent .vof-pm-tier-container');
    if(monthlyContainer) {
        monthlyContainer.innerHTML = '';
        modalState.monthlyTiers.forEach((tier, index) => {
            const tierElement = createTierElement(tier, index);
            monthlyContainer.appendChild(tierElement);
        });
    }
    // Initialize yearly container (if needed)
    if(modalState.isMultiPricingOn) {
        const yearlyContainer = document.querySelector('#vof-pm-yearlyContent .vof-pm-tier-container');
        if(yearlyContainer) {
            yearlyContainer.innerHTML = '';
            modalState.yearlyTiers.forEach((tier, index) => {
                const tierElement = createTierElement(tier, index);
                yearlyContainer.appendChild(tierElement);
            });
        }
    }
}

// Update renderTiers function to render appropriate tiers based on selected tab
function renderTiers() {
    const tierContainer = document.querySelector('.vof-pm-tier-container');
    if (!tierContainer) return;

    tierContainer.innerHTML = '';

    // Use the appropriate tier list based on selected interval
    const tiersToRender = modalState.selectedInterval === "month" ? 
        modalState.monthlyTiers: modalState.yearlyTiers;
    
        // Render the tiers

    // modalState.tiers.forEach((tier, index) => {
    tiersToRender.forEach((tier, index) => {
        const tierElement = createTierElement(tier, index);
        tierContainer.appendChild(tierElement);
    });
}

// Update the renderYearlyContent tab to show actual yearly tiers
function renderYearlyContent() {
    const yearlyContent = document.getElementById('vof-pm-yearlyContent');
    if(!yearlyContent) return;

    // Only replace the placeholder message if we have yearly tiers
    if(modalState.yearlyTiers && modalState.yearlyTiers.length > 0) {
        yearlyContent.innerHTML = `
            <div class="vof-pm-tier-container yearly-container">
                <!-- Yearly tiers will be rendered here -->
            </div>
        `;
    }
}

// Update switchTab function to track selected interval
function switchTab(event) {
    const tabName = event.target.getAttribute('data-tab');

    // Update selected interval based on tab selection
    modalState.selectedInterval = tabName === "monthly" ? "month" : "year";
    
    const currentTabBtns = document.querySelectorAll('.vof-pm-tab-btn');
    const currentTabContents = document.querySelectorAll('.vof-pm-tab-content');
    
    currentTabBtns.forEach(btn => btn.classList.remove('vof-pm-active'));
    currentTabContents.forEach(content => content.classList.remove('vof-pm-active'));
    
    event.target.classList.add('vof-pm-active');
    document.getElementById(`vof-pm-${tabName}Content`).classList.add('vof-pm-active');

    // Render the appropriate tiers for the selected tab
    if(tabName === "monthly") { // monthly
        const monthlyContainer = document.querySelector('#vof-pm-monthlyContent .vof-pm-tier-container');
        if(monthlyContainer){
            monthlyContainer.innerHTML = '';
            modalState.monthlyTiers.forEach((tier, index) => {
                const tierElement = createTierElement(tier,index);
                monthlyContainer.appendChild(tierElement);
            });
        }
    } else { // yearly
        const yearlyContainer = document.querySelector('#vof-pm-yearlyContent .vof-pm-tier-container');
        if(yearlyContainer){
            yearlyContainer.innerHTML = '';
            modalState.yearlyTiers.forEach((tier, index) => {
                const tierElement = createTierElement(tier,index);
                yearlyContainer.appendChild(tierElement);
            });
        }
    }

    // Re-render tiers with appropriate pricing (based on selected interval)
    // renderTiers();
}

// Core Modal Functions - Updated to handle multiPricing Structure
function updateModalState(response) {
    console.log('VOF Debug: Updating modal state with response:', response);

    try {
        if (response && response.pricing_data) {
            // Extract pricing data from API response (which comes from the API)
            const { is_multi_pricing_on, tier_limits } = response.pricing_data;
            
            // Check if we have tier_limits from the API which includes category-based restrictions
            if (tier_limits && Array.isArray(tier_limits)) {
                console.log('VOF Debug: Found tier limits from API with category restrictions:', tier_limits);
                
                // Process the tier limits to separate monthly and yearly tiers
                const monthlyTiers = [];
                const yearlyTiers = [];
                
                tier_limits.forEach(tier => {
                    // Clone the tier to avoid reference issues
                    const tierCopy = {...tier};
                    
                    // Check interval and add to appropriate array
                    if (tierCopy.interval === 'year') {
                        yearlyTiers.push(tierCopy);
                    } else {
                        monthlyTiers.push(tierCopy);
                    }
                });
                
                // Use the tier limits from the API which include category-based restrictions (isGrayOut)
                modalState = {
                    isMultiPricingOn: is_multi_pricing_on !== undefined ? is_multi_pricing_on : defaultState.isMultiPricingOn,
                    isApiData: true,
                    isoCurrencyCode: response.pricing_data.iso_currency_code || adminSettings.iso_currency_code || 'USD',
                    monthlyTiers: monthlyTiers.length > 0 ? monthlyTiers.map(tier => ({
                        ...tier,
                        stripePriceIdTest: tier.stripePriceIdTest || '',
                        stripePriceIdLive: tier.stripePriceIdLive || '',
                        stripeLookupKeyTest: tier.stripeLookupKeyTest || '',
                        stripeLookupKeyLive: tier.stripeLookupKeyLive || ''
                    })) : [...defaultState.monthlyTiers],
                    yearlyTiers: yearlyTiers.length > 0 ? yearlyTiers.map(tier => ({
                        ...tier,
                        stripePriceIdTest: tier.stripePriceIdTest || '',
                        stripePriceIdLive: tier.stripePriceIdLive || '',
                        stripeLookupKeyTest: tier.stripeLookupKeyTest || '',
                        stripeLookupKeyLive: tier.stripeLookupKeyLive || ''
                    })) : [...defaultState.yearlyTiers],
                    selectedInterval: "month",              // default to monthly pricing scheme
                    customer_meta: response.customer_meta,  // Make sure this exists
                    category_id: response.post_category_data, // Store the category ID
                    pricingModalTitle: adminSettings.pricing_modal_title || 'Select Your Plan',
                    tab_label_monthly: adminSettings.tab_label_monthly || 'Monthly Plans',
                    tab_label_yearly: adminSettings.tab_label_yearly || 'Yearly Plans',
                };
                console.log('VOF Debug: Updated modal state with category-based tier restrictions:', modalState);
            } else {
                // Fall back to admin settings or default data if no tier_limits available
                console.warn('VOF Debug: No tier_limits found in API response, using admin settings with default isGrayOut values');
                
                // Use admin settings if available, otherwise use API data with fallback to defaults
                const adminMultiPricing = adminSettings.is_multi_pricing_on !== undefined ? 
                    adminSettings.is_multi_pricing_on : 
                    (is_multi_pricing_on !== undefined ? is_multi_pricing_on : defaultState.isMultiPricingOn);
                    
                const adminMonthlyTiers = adminSettings.monthly_tiers && adminSettings.monthly_tiers.length ? 
                    adminSettings.monthly_tiers.map(tier => ({
                        ...tier,
                        stripePriceIdTest: tier.stripePriceIdTest || '',
                        stripePriceIdLive: tier.stripePriceIdLive || '',
                        stripeLookupKeyTest: tier.stripeLookupKeyTest || '',
                        stripeLookupKeyLive: tier.stripeLookupKeyLive || ''
                    })) : [...defaultState.monthlyTiers];
                    
                const adminYearlyTiers = adminSettings.yearly_tiers && adminSettings.yearly_tiers.length ? 
                    adminSettings.yearly_tiers.map(tier => ({
                        ...tier,
                        stripePriceIdTest: tier.stripePriceIdTest || '',
                        stripePriceIdLive: tier.stripePriceIdLive || '',
                        stripeLookupKeyTest: tier.stripeLookupKeyTest || '',
                        stripeLookupKeyLive: tier.stripeLookupKeyLive || ''
                    })) : [...defaultState.yearlyTiers];
                
                modalState = {
                    isMultiPricingOn: adminMultiPricing,
                    isApiData: true,
                    isoCurrencyCode: response.pricing_data.iso_currency_code || adminSettings.iso_currency_code || 'USD',
                    pricingModalTitle: adminSettings.pricing_modal_title || 'Select Your Plan',
                    tab_label_monthly: adminSettings.tab_label_monthly || 'Monthly Plans',
                    tab_label_yearly: adminSettings.tab_label_yearly || 'Yearly Plans',
                    monthlyTiers: adminMonthlyTiers,
                    yearlyTiers: adminYearlyTiers,
                    selectedInterval: "month",            // default to monthly pricing scheme
                    customer_meta: response.customer_meta, // Make sure this exists
                    category_id: response.post_category_data // Store the category ID
                };
                console.log('VOF Debug: Updated modal state with admin settings (no category restrictions):', modalState);
            }
        } else {
            console.warn('VOF Debug: Invalid API response format, using admin settings or fallback data');
            
            // Use admin settings if available, otherwise fallback to defaults
            modalState = { 
                isMultiPricingOn: adminSettings.is_multi_pricing_on !== undefined ? 
                    adminSettings.is_multi_pricing_on : defaultState.isMultiPricingOn,
                isApiData: defaultState.isApiData,
                isoCurrencyCode: adminSettings.iso_currency_code || 'USD',
                monthlyTiers: adminSettings.monthly_tiers && adminSettings.monthly_tiers.length ? 
                    adminSettings.monthly_tiers.map(tier => ({
                        ...tier,
                        stripePriceIdTest: tier.stripePriceIdTest || '',
                        stripePriceIdLive: tier.stripePriceIdLive || '',
                        stripeLookupKeyTest: tier.stripeLookupKeyTest || '',
                        stripeLookupKeyLive: tier.stripeLookupKeyLive || ''
                    })) : [...defaultState.monthlyTiers],
                yearlyTiers: adminSettings.yearly_tiers && adminSettings.yearly_tiers.length ? 
                    adminSettings.yearly_tiers.map(tier => ({
                        ...tier,
                        stripePriceIdTest: tier.stripePriceIdTest || '',
                        stripePriceIdLive: tier.stripePriceIdLive || '',
                        stripeLookupKeyTest: tier.stripeLookupKeyTest || '',
                        stripeLookupKeyLive: tier.stripeLookupKeyLive || ''
                    })) : [...defaultState.yearlyTiers],
                selectedInterval: "month", // default to monthly pricing scheme
                pricingModalTitle: adminSettings.pricing_modal_title || 'Select Your Plan',
                tab_label_monthly: adminSettings.tab_label_monthly || 'Monthly Plans',
                tab_label_yearly: adminSettings.tab_label_yearly || 'Yearly Plans'
            };
        }

        renderTabs();
        renderTiers();
    } catch (error) {
        console.error('VOF Debug: Error updating modal state:', error);
        
        // Use admin settings if available, otherwise fallback to defaults
        modalState = { 
            isMultiPricingOn: adminSettings.is_multi_pricing_on !== undefined ? 
                adminSettings.is_multi_pricing_on : defaultState.isMultiPricingOn,
            isApiData: defaultState.isApiData,
            isoCurrencyCode: adminSettings.iso_currency_code || 'USD',
            pricingModalTitle: adminSettings.pricing_modal_title || 'Select Your Plan',
            tab_label_monthly: adminSettings.tab_label_monthly || 'Monthly Plans',
            tab_label_yearly: adminSettings.tab_label_yearly || 'Yearly Plans',
            monthlyTiers: adminSettings.monthly_tiers && adminSettings.monthly_tiers.length ? 
                adminSettings.monthly_tiers : [...defaultState.monthlyTiers],
            yearlyTiers: adminSettings.yearly_tiers && adminSettings.yearly_tiers.length ? 
                adminSettings.yearly_tiers : [...defaultState.yearlyTiers],
            selectedInterval: "month", // default to monthly pricing scheme
        };
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

    // Update state based on API data or use admin settings with fallback
    if (apiData) { 
        // If API data is provided, update the modal state
        // The updateModalState function now prioritizes admin settings
        updateModalState(apiData);
    } else if (typeof vofPricingModalConfig !== 'undefined' || VOF_MODAL_CONFIG.enableFallback) {
        console.log('VOF Debug: Using admin settings or fallback data');
        
        // Use admin settings if available, otherwise use fallback data
        modalState = { 
            isMultiPricingOn: adminSettings.is_multi_pricing_on !== undefined ? 
                adminSettings.is_multi_pricing_on : defaultState.isMultiPricingOn,
            isApiData: false,
            isoCurrencyCode: adminSettings.iso_currency_code || 'USD',
            pricingModalTitle: adminSettings.pricing_modal_title || 'Select Your Plan',
            tab_label_monthly: adminSettings.tab_label_monthly || 'Monthly Plans',
            tab_label_yearly: adminSettings.tab_label_yearly || 'Yearly Plans',
            monthlyTiers: adminSettings.monthly_tiers && adminSettings.monthly_tiers.length ? 
                adminSettings.monthly_tiers : [...defaultState.monthlyTiers],
            yearlyTiers: adminSettings.yearly_tiers && adminSettings.yearly_tiers.length ? 
                adminSettings.yearly_tiers : [...defaultState.yearlyTiers],
            selectedInterval: "month" // Default to monthly pricing scheme
        };
        
        console.log('VOF Debug: Using settings:', modalState);
        renderTabs();
        renderTiers();
    } else {
        console.error('VOF Debug: No API data provided, no admin settings available, and fallback disabled');
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