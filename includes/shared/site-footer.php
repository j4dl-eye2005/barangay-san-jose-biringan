    <button class="policy-back-top" id="policy-back-top" type="button" data-action-click="window.scrollTo({top: 0, behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth'})"><i class="fas fa-arrow-up" aria-hidden="true"></i><span>Back to top</span></button>

    <?php if (empty($suppressSiteDisclaimer)): ?>
    <aside class="site-disclaimer-bar" aria-label="Site disclaimer">
        <p class="site-disclaimer"><strong>Disclaimer:</strong> This website is created for demonstration and academic purposes only as part of an SIA subject project. It is not a real company, official service, registered business, or commercial website. No claims are made regarding actual business operations, legal standing, or real-world services.</p>
    </aside>
    <?php endif; ?>

    <footer class="site-footer" id="site-footer">
        <div class="site-footer-inner">
            <div class="site-footer-public-layout" hidden>
                <div class="site-footer-public-columns">
                    <div class="site-footer-contact-column">
                        <div class="site-footer-brand">
                            <img src="assets/barangay-seal.svg" alt="" class="site-footer-seal" width="128" height="128" loading="lazy" decoding="async" aria-hidden="true">
                            <div class="site-footer-brand-copy">
                                <strong>Barangay San Jose</strong>
                                <span>Digital services and public records</span>
                            </div>
                            <span class="site-footer-brand-divider" aria-hidden="true"></span>
                            <span class="site-footer-brand-statement">Digital Disclosure &amp;<br>Document Request Portal</span>
                        </div>
                        <address class="site-footer-contact-details">
                            <span>Barangay San Jose, Biringan City</span>
                            <span>Monday to Friday, 8:00 AM to 5:00 PM</span>
                            <a href="tel:+639566980306">+63 956 698 0306</a>
                        </address>
                    </div>
                    <nav class="site-footer-link-column" aria-label="Services">
                        <h2 class="site-footer-column-heading">Services</h2>
                        <ul class="site-footer-column-links">
                            <li><button class="site-footer-link" type="button" data-action-click="showSection('resident-dashboard')">Request a document</button></li>
                            <li><button class="site-footer-link" type="button" data-action-click="openResidentProfileQuickLink('track')">Track my requests</button></li>
                            <li><button class="site-footer-link" type="button" data-action-click="showSection('bulletin')">Public Records</button></li>
                        </ul>
                    </nav>
                    <nav class="site-footer-link-column" aria-label="Legal">
                        <h2 class="site-footer-column-heading">Legal</h2>
                        <ul class="site-footer-column-links">
                            <li><button class="site-footer-link" type="button" data-action-click="showSection('privacy-policy')">Privacy Policy</button></li>
                            <li><button class="site-footer-link" type="button" data-action-click="showSection('terms')">Terms</button></li>
                            <li><button class="site-footer-link" type="button" data-action-click="showSection('moderation')">Moderation</button></li>
                            <li><button class="site-footer-link" type="button" data-action-click="showSection('copyright')">Copyright</button></li>
                        </ul>
                    </nav>
                </div>
            </div>
            <div class="site-footer-legacy-layout">
                <div class="site-footer-brand">
                    <img src="assets/barangay-seal.svg" alt="" class="site-footer-seal" width="128" height="128" loading="lazy" decoding="async" aria-hidden="true">
                    <div class="site-footer-brand-copy">
                        <strong>Barangay San Jose</strong>
                        <span>Digital services and public records</span>
                    </div>
                </div>
                <nav class="footer-links" aria-label="Policy links">
                    <button type="button" data-action-click="showSection('privacy-policy')">Privacy Policy</button>
                    <button type="button" data-action-click="showSection('terms')">Terms</button>
                    <button type="button" data-action-click="showSection('moderation')">Moderation</button>
                    <button type="button" data-action-click="showSection('copyright')">Copyright</button>
                </nav>
            </div>
            <div class="site-footer-bottom">
                <p class="site-footer-note">&copy; <span id="footer-year">2026</span> Barangay San Jose. All rights reserved.</p>
                <button class="site-footer-back-top" type="button" data-action-click="window.scrollTo({top: 0, behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth'})">Back to top <span aria-hidden="true">↑</span></button>
            </div>
        </div>
    </footer>
