document.addEventListener('DOMContentLoaded', () => {
    
    // --- Mobile Menu Toggle ---
    const mobileMenuBtn = document.querySelector('.mobile-menu-btn');
    const navLinks = document.querySelector('.nav-links');
    if (mobileMenuBtn && navLinks) {
        mobileMenuBtn.addEventListener('click', () => {
            navLinks.classList.toggle('active');
        });
    }

    // --- Header background change on scroll ---
    window.addEventListener('scroll', () => {
        const header = document.querySelector('.main-header');
        if (header) {
            if (window.scrollY > 50) {
                header.style.boxShadow = '0 4px 20px rgba(0,0,0,0.1)';
            } else {
                header.style.boxShadow = 'none';
            }
        }
    });

    // --- Hero Slider Logic ---
    const slider = document.getElementById('heroSlider');
    if (slider) {
        const slides = slider.querySelectorAll('.slide');
        const prevBtn = slider.querySelector('.prev-btn');
        const nextBtn = slider.querySelector('.next-btn');
        let currentSlide = 0;
        let slideInterval;

        function showSlide(index) {
            slides.forEach(s => s.classList.remove('active'));
            if (index >= slides.length) currentSlide = 0;
            if (index < 0) currentSlide = slides.length - 1;
            slides[currentSlide].classList.add('active');
        }

        function nextSlide() {
            currentSlide++;
            showSlide(currentSlide);
        }

        function prevSlide() {
            currentSlide--;
            showSlide(currentSlide);
        }

        // Create dots
        const dotsContainer = slider.querySelector('.slider-dots');
        slides.forEach((_, i) => {
            const dot = document.createElement('div');
            dot.style.cssText = 'width:10px;height:10px;border-radius:50%;background:rgba(255,255,255,0.5);cursor:pointer;transition:background 0.3s';
            dot.addEventListener('click', () => { currentSlide = i; showSlide(currentSlide); resetInterval(); });
            dotsContainer.appendChild(dot);
        });

        function updateDots() {
            dotsContainer.querySelectorAll('div').forEach((d, i) => {
                d.style.background = i === currentSlide ? 'white' : 'rgba(255,255,255,0.5)';
            });
        }

        function showSlide(index) {
            slides.forEach(s => s.classList.remove('active'));
            if (index >= slides.length) currentSlide = 0;
            if (index < 0) currentSlide = slides.length - 1;
            slides[currentSlide].classList.add('active');
            updateDots();
        }
        showSlide(0);

        if (nextBtn && prevBtn) {
            nextBtn.addEventListener('click', () => {
                nextSlide();
                resetInterval();
            });
            prevBtn.addEventListener('click', () => {
                prevSlide();
                resetInterval();
            });
        }

        function resetInterval() {
            clearInterval(slideInterval);
            slideInterval = setInterval(nextSlide, 5000);
        }
        
        slideInterval = setInterval(nextSlide, 5000);
    }

    // --- Verification Logic ---
    const searchBtn = document.getElementById('searchBtn');
    const memberIdInput = document.getElementById('memberIdInput');
    const searchMessage = document.getElementById('searchMessage');
    const idCardResult = document.getElementById('idCardResult');

    const memberPhoto = document.getElementById('memberPhoto');
    const displayMemberId = document.getElementById('displayMemberId');
    const memberName = document.getElementById('memberName');
    const memberDesignation = document.getElementById('memberDesignation');
    const memberState = document.getElementById('memberState');
    const memberValidity = document.getElementById('memberValidity');

    if (searchBtn && memberIdInput) {
        searchBtn.addEventListener('click', performSearch);
        memberIdInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                performSearch();
            }
        });
    }

    function normalizeId(id) {
        return id.toUpperCase()
                 .replace(/\s+/g, '')       // remove spaces
                 .replace(/\./g, '')        // remove dots
                 .replace(/^(REGDNO|REGD|REG|NO)/g, '') // remove prefixes
                 .replace(/^NO/g, '');      // remove leftover NO prefix
    }

    function performSearch() {
        const query = memberIdInput.value.trim();
        if (!query) {
            showMessage('Please enter a valid TACC ID.', 'msg-error');
            hideCard();
            return;
        }

        searchMessage.textContent = 'Searching...';
        searchMessage.className = 'search-message';
        hideCard();

        fetch('data.json')
            .then(response => response.json())
            .then(membersDatabase => {
                setTimeout(() => {
                    const normalizedQuery = normalizeId(query);
                    const member = membersDatabase.find(m => normalizeId(m.id) === normalizedQuery);
                    if (member) {
                        displayCard(member);
                        showMessage('Member verified successfully.', 'msg-success');
                    } else {
                        showMessage(`No active member found with ID: ${query}`, 'msg-error');
                    }
                }, 400);
            })
            .catch(error => {
                console.error('Error fetching data:', error);
                showMessage('Error connecting to database. Please try again later.', 'msg-error');
            });
    }

    function showMessage(msg, className) {
        searchMessage.textContent = msg;
        searchMessage.className = `search-message ${className}`;
    }

    function displayCard(data) {
        memberPhoto.src = data.photo || `https://ui-avatars.com/api/?name=${encodeURIComponent(data.name)}&background=112240&color=fff&size=150`;
        displayMemberId.textContent = data.id;
        memberName.textContent = data.name;
        memberDesignation.textContent = data.designation;
        memberState.textContent = data.state || 'N/A';
        memberValidity.textContent = data.validity || 'N/A';
        idCardResult.classList.remove('hidden');
    }

    function hideCard() {
        idCardResult.classList.add('hidden');
    }

    // --- Dynamic Team Loading (index.html & team.html) ---
    const dynamicPresidentIndex = document.getElementById('dynamic-president');
    const dynamicLeadersIndex = document.getElementById('dynamic-leaders');
    const dynamicPresidentTeam = document.getElementById('dynamic-team-president');
    const dynamicLeadersTeam = document.getElementById('dynamic-team-grid');

    if (dynamicPresidentIndex || dynamicPresidentTeam) {
        fetch('data.json')
            .then(response => response.json())
            .then(allMembers => {
                const members = allMembers.filter(m => m.show_on_website === true);
                
                // Find National President
                const president = members.find(m => m.designation.toLowerCase().includes('national president')) || members[0];
                const otherLeaders = members.filter(m => m.id !== (president ? president.id : ''));
                
                // Render President on Index
                if (dynamicPresidentIndex && president) {
                    dynamicPresidentIndex.innerHTML = `
                        <img src="${president.photo || 'assets/images/president_portrait_1781000237855.png'}" alt="President" class="leader-img-large">
                        <h3 class="leader-name">${president.name}</h3>
                        <p class="leader-title">${president.designation}</p>
                        <p class="leader-desc">"Our battle is not against an individual, but against the system that harbors corruption. Together, we can build a strong, honest India."</p>
                    `;
                }

                // Render President on Team Page
                if (dynamicPresidentTeam && president) {
                    dynamicPresidentTeam.innerHTML = `
                        <img src="${president.photo || 'assets/images/president_portrait_1781000237855.png'}" alt="National President">
                        <div class="president-info">
                            <h4>National President</h4>
                            <h2>${president.name}</h2>
                            <p>"I have dedicated my life to ensuring a corruption-free society. Through Team Against Corruption and Crime, we aim to unite citizens across India to fight injustice and build a stronger, transparent nation for future generations."</p>
                        </div>
                    `;
                }

                // Render Leaders on Index (Max 4)
                if (dynamicLeadersIndex) {
                    dynamicLeadersIndex.innerHTML = otherLeaders.slice(0, 4).map(m => `
                        <div class="leader-card">
                            <img src="${m.photo || 'assets/images/team_leader_1781000276252.png'}" alt="Leader" class="leader-img-small">
                            <h4>${m.name}</h4>
                            <p>${m.designation}</p>
                        </div>
                    `).join('');
                }

                // Render Leaders on Team Page (All)
                if (dynamicLeadersTeam) {
                    dynamicLeadersTeam.innerHTML = otherLeaders.map(m => `
                        <div class="leader-card">
                            <img src="${m.photo || 'assets/images/team_leader_1781000276252.png'}" alt="Leader" class="leader-img-small">
                            <h4>${m.name}</h4>
                            <p>${m.designation}</p>
                        </div>
                    `).join('');
                }
            })
            .catch(console.error);
    }

    // --- Dynamic Gallery ---
    const dynamicGalleryIndex = document.getElementById('dynamic-gallery-index');
    if (dynamicGalleryIndex) {
        fetch('gallery.json')
            .then(res => res.json())
            .then(images => {
                if (images.length === 0) {
                    dynamicGalleryIndex.innerHTML = '<p>No images in gallery yet.</p>';
                    return;
                }
                dynamicGalleryIndex.innerHTML = images.slice(0, 4).map(img => `
                    <img src="${img}" alt="Gallery Image">
                `).join('');
            })
            .catch(() => {
                // Ignore if gallery.json doesn't exist yet
            });
    }

    // --- Dynamic Certificates ---
    const dynamicCertificatesIndex = document.getElementById('dynamic-certificates-index');
    const dynamicCertificatesAll = document.getElementById('dynamic-certificates-all');
    if (dynamicCertificatesIndex || dynamicCertificatesAll) {
        fetch('certificates.json')
            .then(res => res.json())
            .then(certs => {
                if (certs.length === 0) {
                    const msg = '<p>No certificates available yet.</p>';
                    if (dynamicCertificatesIndex) dynamicCertificatesIndex.innerHTML = msg;
                    if (dynamicCertificatesAll) dynamicCertificatesAll.innerHTML = msg;
                    return;
                }
                if (dynamicCertificatesIndex) {
                    const renderIndexCerts = (data) => data.map(cert => `
                        <div class="cert-item" onclick="openLightbox('${cert.image}', '${cert.caption || cert.title}')">
                            <img src="${cert.image}" alt="${cert.title}">
                            <div class="cert-item-body">
                                <h4>${cert.title}</h4>
                                <a href="${cert.image}" target="_blank" onclick="event.stopPropagation()"><i class="fas fa-external-link-alt"></i> View Full</a>
                            </div>
                        </div>
                    `).join('');
                    dynamicCertificatesIndex.innerHTML = renderIndexCerts(certs.slice(0, 4));
                }
                
                if (dynamicCertificatesAll) {
                    const renderAllCerts = (data) => data.map(cert => `
                        <div class="cert-card" onclick="openLightbox('${cert.image}', '${cert.caption || cert.title}')">
                            <div class="cert-img-container">
                                <img src="${cert.image}" alt="${cert.title}" loading="lazy">
                                <div class="cert-overlay">
                                    <div class="cert-zoom-btn"><i class="fas fa-search-plus"></i></div>
                                </div>
                            </div>
                            <div class="cert-details">
                                <h3>${cert.title}</h3>
                                <p>${cert.caption}</p>
                                <div class="cert-btn-group">
                                    <a href="${cert.image}" target="_blank" class="cert-btn cert-btn-outline" onclick="event.stopPropagation()"><i class="fas fa-external-link-alt"></i> View Full</a>
                                </div>
                            </div>
                        </div>
                    `).join('');
                    dynamicCertificatesAll.innerHTML = renderAllCerts(certs);
                }
            })
            .catch(console.error);
    }

    // --- Dynamic Settings & Contact Info ---
    fetch('settings.json')
        .then(res => res.json())
        .then(settings => {
            // QR Code
            const dynamicDonateQr = document.getElementById('dynamic-donate-qr');
            if (dynamicDonateQr && settings.qr_code_path) {
                dynamicDonateQr.src = settings.qr_code_path;
            }
            
            // Populate dynamic contact fields across pages
            if (settings.email) {
                document.querySelectorAll('.dyn-email').forEach(el => {
                    if(el.tagName === 'A') el.href = `mailto:${settings.email}`;
                    el.textContent = settings.email;
                    // For icons preserving text content
                    if(el.classList.contains('preserve-icon')) el.innerHTML = `<i class="fas fa-envelope"></i> ${settings.email}`;
                });
            }
            if (settings.phone1) {
                document.querySelectorAll('.dyn-phone1').forEach(el => {
                    if(el.tagName === 'A') el.href = `tel:${settings.phone1.replace(/\D/g,'')}`;
                    el.textContent = settings.phone1;
                    if(el.classList.contains('preserve-icon')) el.innerHTML = `<i class="fas fa-phone-alt"></i> ${settings.phone1}`;
                });
            }
            if (settings.phone2) {
                document.querySelectorAll('.dyn-phone2').forEach(el => {
                    if(el.tagName === 'A') el.href = `tel:${settings.phone2.replace(/\D/g,'')}`;
                    el.textContent = settings.phone2;
                });
            }
            if (settings.address) {
                document.querySelectorAll('.dyn-address').forEach(el => {
                    el.textContent = settings.address;
                    if(el.classList.contains('preserve-icon')) el.innerHTML = `<i class="fas fa-map-marker-alt"></i> ${settings.address}`;
                });
            }
            if (settings.bank_account_name) {
                document.querySelectorAll('.dyn-bank-acc-name').forEach(el => el.textContent = settings.bank_account_name);
            }
            if (settings.bank_name) {
                document.querySelectorAll('.dyn-bank-name').forEach(el => el.textContent = settings.bank_name);
            }
            if (settings.bank_branch) {
                document.querySelectorAll('.dyn-bank-branch').forEach(el => el.textContent = settings.bank_branch);
            }
            if (settings.bank_account_number) {
                document.querySelectorAll('.dyn-bank-acc-no').forEach(el => el.textContent = settings.bank_account_number);
            }
            if (settings.bank_ifsc) {
                document.querySelectorAll('.dyn-bank-ifsc').forEach(el => el.textContent = settings.bank_ifsc);
            }
        })
        .catch(console.error);

    // --- Contact Form AJAX Submit ---
    const contactForm = document.getElementById('contactForm');
    const contactFormMessage = document.getElementById('contactFormMessage');
    
    if (contactForm) {
        contactForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            const submitBtn = document.getElementById('contactSubmitBtn');
            submitBtn.textContent = 'Sending...';
            submitBtn.disabled = true;

            fetch('contact_process.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                contactFormMessage.innerHTML = `<div class="msg ${data.status === 'success' ? 'success' : 'error'}">${data.message}</div>`;
                if(data.status === 'success') contactForm.reset();
            })
            .catch(() => {
                contactFormMessage.innerHTML = `<div class="msg error">An error occurred. Please try again.</div>`;
            })
            .finally(() => {
                submitBtn.textContent = 'Send Message';
                submitBtn.disabled = false;
            });
        });
    }
});
