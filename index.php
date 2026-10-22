<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>SolarGo | Power That Does Not Cut Out</title>

    <meta name="description"
          content="Discover solar products, installation services, maintenance, and flexible payment options with SolarGo.">

    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body>

    <!-- NAVIGATION BAR -->
    <header class="header">
        <nav class="navbar container">

            <a href="index.php" class="logo">
                <span class="logo-icon">☀</span>
                Solar<span>Go</span>
            </a>

            <button class="menu-toggle"
                    id="menuToggle"
                    aria-label="Toggle navigation"
                    aria-expanded="false">
                ☰
            </button>

            <ul class="nav-links" id="navLinks">
                <li><a href="index.php" class="active">Home</a></li>
                <li><a href="products.php">Products</a></li>
                <li><a href="services.php">Services</a></li>
                <li><a href="about.php">About Us</a></li>
                <li><a href="contact.php">Contact</a></li>
            </ul>

            <div class="nav-buttons">
                <a href="auth/login.php" class="login-btn">Login</a>
                <a href="auth/register.php" class="btn btn-small">Get Started</a>
            </div>

        </nav>
    </header>


    <!-- HERO SECTION -->
    <main>

        <section class="hero">

            <div class="hero-content container">

                <div class="hero-text">

                    <span class="eyebrow">
                        ☀ CLEAN ENERGY. BETTER LIVING.
                    </span>

                    <h1>
                        Power That
                        <span>Does Not Cut Out.</span>
                    </h1>

                    <p>
                        Discover reliable solar products, connect with
                        trusted technicians, and find energy solutions
                        that fit your budget.
                    </p>

                    <div class="hero-buttons">
                        <a href="products.php" class="btn">
                            Explore Products →
                        </a>

                        <a href="services.php" class="btn btn-outline">
                            Find a Solar Solution
                        </a>
                    </div>

                    <div class="hero-stats">

                        <div>
                            <h3>Reliable</h3>
                            <p>Energy solutions</p>
                        </div>

                        <div>
                            <h3>Flexible</h3>
                            <p>Payment options</p>
                        </div>

                        <div>
                            <h3>Trusted</h3>
                            <p>Solar professionals</p>
                        </div>

                    </div>

                </div>

                <div class="hero-image">

                    <div class="sun-decoration">☀</div>

                    <div class="solar-illustration">

                        <div class="illustration-sun"></div>

                        <div class="house">
                            <div class="roof"></div>

                            <div class="house-body">
                                <div class="window"></div>
                                <div class="door"></div>
                            </div>
                        </div>

                        <div class="solar-panel">
                            <div class="panel-grid"></div>
                        </div>

                        <div class="ground"></div>

                    </div>

                    <div class="floating-card">
                        <span class="floating-icon">⚡</span>

                        <div>
                            <strong>Smarter Energy</strong>
                            <p>Power your future</p>
                        </div>
                    </div>

                </div>

            </div>

        </section>


        <!-- BENEFITS SECTION -->
        <section class="benefits">

            <div class="container benefits-grid">

                <div class="benefit-item">
                    <span>🔆</span>
                    <div>
                        <h3>Quality Solar Products</h3>
                        <p>Discover equipment for your energy needs.</p>
                    </div>
                </div>

                <div class="benefit-item">
                    <span>🛠️</span>
                    <div>
                        <h3>Professional Services</h3>
                        <p>Find installation and maintenance support.</p>
                    </div>
                </div>

                <div class="benefit-item">
                    <span>💳</span>
                    <div>
                        <h3>Flexible Payments</h3>
                        <p>Apply for eligible instalment plans.</p>
                    </div>
                </div>

            </div>

        </section>


        <!-- PRODUCTS SECTION -->
        <section class="section products-section" id="products">

            <div class="container">

                <div class="section-heading">

                    <div>
                        <span class="eyebrow">OUR MARKETPLACE</span>

                        <h2>Explore Solar Products</h2>

                        <p>
                            Find solar equipment to help power your
                            home or business.
                        </p>
                    </div>

                    <a href="products.php" class="text-link">
                        View All Products →
                    </a>

                </div>


                <div class="products-grid">

                    <!-- PRODUCT 1 -->
                    <article class="product-card">

                        <div class="product-image battery-image">
                            <span class="product-badge">Energy Storage</span>
                            <div class="product-emoji">🔋</div>
                        </div>

                        <div class="product-info">

                            <span class="product-category">BATTERIES</span>

                            <h3>Solar Battery System</h3>

                            <p>
                                Store solar energy for use when sunlight
                                is unavailable.
                            </p>

                            <div class="product-bottom">
                                <span class="price">View price</span>
                                <a href="products.php" class="product-btn"
                                   aria-label="Explore solar batteries">→</a>
                            </div>

                        </div>

                    </article>


                    <!-- PRODUCT 2 -->
                    <article class="product-card">

                        <div class="product-image panel-image">
                            <span class="product-badge">Solar Power</span>
                            <div class="product-emoji">☀️</div>
                        </div>

                        <div class="product-info">

                            <span class="product-category">SOLAR PANELS</span>

                            <h3>Solar Panel System</h3>

                            <p>
                                Explore solar panels for homes, shops,
                                and businesses.
                            </p>

                            <div class="product-bottom">
                                <span class="price">View price</span>
                                <a href="products.php" class="product-btn"
                                   aria-label="Explore solar panels">→</a>
                            </div>

                        </div>

                    </article>


                    <!-- PRODUCT 3 -->
                    <article class="product-card">

                        <div class="product-image inverter-image">
                            <span class="product-badge">Power Backup</span>
                            <div class="product-emoji">⚡</div>
                        </div>

                        <div class="product-info">

                            <span class="product-category">INVERTERS</span>

                            <h3>Solar Inverter</h3>

                            <p>
                                Discover inverters for your solar
                                installation requirements.
                            </p>

                            <div class="product-bottom">
                                <span class="price">View price</span>
                                <a href="products.php" class="product-btn"
                                   aria-label="Explore solar inverters">→</a>
                            </div>

                        </div>

                    </article>

                </div>

                <p class="demo-notice">
                    These are demonstration cards. Real product listings,
                    prices, and seller information will come from the database.
                </p>

            </div>

        </section>


        <!-- SERVICES SECTION -->
        <section class="section services-section">

            <div class="container">

                <div class="section-heading centered">

                    <span class="eyebrow">WHAT WE OFFER</span>

                    <h2>Everything You Need for Solar Energy</h2>

                    <p>
                        From buying equipment to keeping your system
                        running, SolarGo brings energy solutions together.
                    </p>

                </div>


                <div class="services-grid">

                    <article class="service-card">
                        <div class="service-icon">🛒</div>
                        <h3>Solar Marketplace</h3>
                        <p>
                            Explore solar panels, batteries, inverters,
                            and other approved product listings.
                        </p>
                        <a href="products.php">Explore products →</a>
                    </article>

                    <article class="service-card">
                        <div class="service-icon">🔧</div>
                        <h3>Installation Services</h3>
                        <p>
                            Find technicians who can help install
                            your solar energy system.
                        </p>
                        <a href="services.php">Find services →</a>
                    </article>

                    <article class="service-card">
                        <div class="service-icon">🛠️</div>
                        <h3>Repair & Maintenance</h3>
                        <p>
                            Request help with system inspections,
                            repairs, and routine maintenance.
                        </p>
                        <a href="services.php">Get assistance →</a>
                    </article>

                    <article class="service-card">
                        <div class="service-icon">💳</div>
                        <h3>Instalment Applications</h3>
                        <p>
                            Apply for instalment payments on eligible
                            products, subject to approval.
                        </p>
                        <a href="products.php">Explore options →</a>
                    </article>

                </div>

            </div>

        </section>


        <!-- HOW IT WORKS -->
        <section class="section how-section">

            <div class="container">

                <div class="section-heading centered">

                    <span class="eyebrow">SIMPLE AND CONVENIENT</span>

                    <h2>How SolarGo Works</h2>

                    <p>
                        Start your journey towards more reliable energy
                        in four simple steps.
                    </p>

                </div>


                <div class="steps-grid">

                    <div class="step">
                        <div class="step-number">01</div>
                        <h3>Create an Account</h3>
                        <p>Register as a customer to get started.</p>
                    </div>

                    <div class="step">
                        <div class="step-number">02</div>
                        <h3>Explore Solutions</h3>
                        <p>Find products and services that suit your needs.</p>
                    </div>

                    <div class="step">
                        <div class="step-number">03</div>
                        <h3>Place an Order</h3>
                        <p>Choose your product and submit your order.</p>
                    </div>

                    <div class="step">
                        <div class="step-number">04</div>
                        <h3>Track Your Progress</h3>
                        <p>Follow your order and any approved payment plan.</p>
                    </div>

                </div>

            </div>

        </section>


        <!-- CALL TO ACTION -->
        <section class="cta-section">

            <div class="container cta-content">

                <div>
                    <span class="eyebrow">YOUR ENERGY JOURNEY STARTS HERE</span>

                    <h2>Ready to Explore a Brighter Future?</h2>

                    <p>
                        Join SolarGo to discover solar products,
                        services, and energy solutions.
                    </p>
                </div>

                <a href="auth/register.php" class="btn btn-light">
                    Create Your Account →
                </a>

            </div>

        </section>

    </main>


    <!-- FOOTER -->
    <footer class="footer">

        <div class="container footer-grid">

            <div class="footer-about">

                <a href="index.php" class="logo footer-logo">
                    <span class="logo-icon">☀</span>
                    Solar<span>Go</span>
                </a>

                <p>
                    Connecting people with solar products,
                    services, and smarter energy solutions.
                </p>

                <p class="footer-slogan">
                    Power That Does Not Cut Out.
                </p>

            </div>


            <div>
                <h3>Explore</h3>
                <ul>
                    <li><a href="index.php">Home</a></li>
                    <li><a href="products.php">Products</a></li>
                    <li><a href="services.php">Services</a></li>
                    <li><a href="about.php">About Us</a></li>
                </ul>
            </div>


            <div>
                <h3>Account</h3>
                <ul>
                    <li><a href="auth/login.php">Login</a></li>
                    <li><a href="auth/register.php">Register</a></li>
                </ul>
            </div>


            <div>
                <h3>Our Commitment</h3>
                <p>Convenience, transparency, and access to solar solutions.</p>
            </div>

        </div>


        <div class="footer-bottom">
            <div class="container">
                <p>
                    &copy; <span id="year"></span> SolarGo.
                    All rights reserved.
                </p>
            </div>
        </div>

    </footer>


    <script src="assets/js/script.js"></script>

</body>
</html>