<?php
// footer.php
?>
<style>
    /* --- Enhanced Footer CSS --- */
    :root { /* Make sure variables are available if not defined globally */
        --primary: #4A90E2;
        --primary-dark: #3A7BCD;
        --secondary: #50E3C2;
        --accent: #F5A623;
        --light: #f8f9fa;
        --dark: #2c3e50;
        --grey: #7f8c8d;
        --white: #ffffff;
        --transition-smooth: all 0.35s ease-in-out;
    }

    footer {
        background-color: var(--dark);
        color: rgba(255, 255, 255, 0.85);
        padding: 60px 25px; /* Adjusted padding, removed extra top padding */
        text-align: center;
        position: relative;
        margin-top: auto; /* Pushes footer down if content is short */
        flex-shrink: 0; /* Prevents footer from shrinking */
    }

    .footer-container {
        max-width: 1250px;
        margin: 0 auto;
        position: relative;
        z-index: 2;
    }

    .footer-links {
        margin-bottom: 45px; /* Slightly more space */
        display: flex; /* Use flexbox for alignment */
        justify-content: center; /* Center links */
        gap: 15px; /* Spacing between links */
        flex-wrap: wrap; /* Allow links to wrap on smaller screens */
    }

    .footer-links a {
        color: var(--secondary);
        text-decoration: none;
        /* margin: 0 20px; Removed in favor of gap */
        transition: color 0.3s ease, transform 0.3s ease;
        position: relative;
        padding-bottom: 8px;
        font-weight: 500;
        display: inline-block;
    }

    .footer-links a::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0; /* Align underline start to the left of the text */
        width: 0;
        height: 2px;
        background: var(--secondary);
        transition: width 0.35s ease-out;
        border-radius: 1px;
    }

    .footer-links a:hover {
        color: var(--white);
        transform: translateY(-3px);
    }

    .footer-links a:hover::after {
        width: 100%; /* Underline covers the full width on hover */
    }

    .social-links {
        margin: 45px 0; /* Adjusted margin */
    }

    .social-links a {
        color: rgba(255, 255, 255, 0.75);
        font-size: 1.8rem;
        margin: 0 18px;
        transition: var(--transition-smooth);
        display: inline-block;
    }

    .social-links a:hover {
        color: var(--secondary);
        transform: translateY(-6px) scale(1.25) rotate(5deg);
        filter: drop-shadow(0 4px 8px rgba(80, 227, 194, 0.3));
    }

    .copyright {
        font-size: 0.95rem;
        color: var(--grey);
        margin-top: 40px; /* Adjusted margin */
    }

    /* Responsive Footer Adjustments */
    @media (max-width: 992px) {
        .footer-links a { margin: 0 15px; } /* Keep some horizontal margin on larger small screens */
        footer { padding: 50px 20px; }
    }

    @media (max-width: 768px) {
        .footer-links { gap: 10px 20px; } /* Adjust gap for wrapping */
        .footer-links a { margin: 0 10px; font-size: 0.95rem;}
        .social-links a { margin: 0 12px; font-size: 1.6rem;}
        footer { padding: 40px 15px;}
    }

     @media (max-width: 480px) {
        .footer-links { flex-direction: column; /* Stack links vertically */ gap: 15px; }
        .footer-links a { display: block; margin: 0 auto; } /* Center stacked links */
        .social-links { margin: 35px 0;}
        .social-links a { margin: 0 10px; font-size: 1.5rem;}
        footer { padding: 35px 15px;}
    }

</style>

<footer>
    <div class="footer-container">
        <div class="footer-links">
            <a href="index.php">Home</a>
            <a href="#features">Features</a>
            <a href="about.php">About</a> <!-- Assuming you have or will have an about.php -->
            <a href="contact.php">Contact</a>
            <a href="help.php">Help</a>
            <a href="#">FAQ</a> <!-- Link to an actual FAQ page if you have one -->
        </div>
        <div class="social-links">
            <a href="#" title="Facebook"><i class="fab fa-facebook-f"></i></a>
            <a href="#" title="Twitter"><i class="fab fa-twitter"></i></a>
            <a href="#" title="Telegram"><i class="fab fa-telegram-plane"></i></a>
            <a href="#" title="LinkedIn"><i class="fab fa-linkedin-in"></i></a>
        </div>
        <div class="copyright">
            © <?php echo date("Y"); ?> DMU Complaint Management System - Group 4 Project. All rights reserved.
        </div>
    </div>
</footer>

<?php
// No specific footer JS needed based on the original code.
// If you add JS *only* for the footer later, put it here within <script> tags.
?>