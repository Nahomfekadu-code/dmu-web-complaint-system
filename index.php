<?php
session_start();
require_once 'db_connect.php'; // Make sure this path is correct

// Redirect logged-in users
if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    $role = strtolower($_SESSION['role']);
    // Ensure the paths below are correct relative to this index.php file
    if ($role == 'user') {
        header("Location: user/dashboard.php");
        exit;
    } elseif ($role == 'handler') {
        header("Location: handler/dashboard.php");
        exit;
    } elseif ($role == 'admin') {
        header("Location: admin/dashboard.php");
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DMU Complaint System | Welcome</title>
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            /* Refined Color Palette */
            --primary: #4A90E2; /* Bright Blue */
            --primary-dark: #3A7BCD; /* Slightly Darker Blue */
            --secondary: #50E3C2; /* Teal */
            --accent: #F5A623; /* Optional Accent - Gold/Orange */
            --light: #f8f9fa; /* Off-white */
            --dark: #2c3e50; /* Dark Blue-Grey */
            --grey: #7f8c8d; /* Mid Grey */
            --white: #ffffff;
            --card-bg: rgba(255, 255, 255, 0.5); /* Increased opacity for blur */
            --radius: 15px; /* Slightly more rounded */
            --shadow: 0 10px 30px rgba(0, 0, 0, 0.07); /* Softer, larger shadow */
            --shadow-hover: 0 15px 45px rgba(0, 0, 0, 0.12); /* Enhanced hover shadow */
            --transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275); /* Bouncier transition */
            --transition-smooth: all 0.35s ease-in-out; /* Smoother overall */
            --glass-border: rgba(255, 255, 255, 0.3); /* Slightly more visible border */
        }

        * {
            margin: 0;
            padding: 0;
  box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            color: var(--dark);
            line-height: 1.7;
            background-color: var(--light);
            overflow-x: hidden; /* Prevent horizontal scrollbars */
        }

        /* --- Enhanced Top Navigation --- */
        .top-nav {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            background: rgba(255, 255, 255, 0.75);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.05);
            z-index: 1000;
            padding: 15px 0;
            transition: padding 0.3s ease-in-out, background-color 0.3s ease-in-out, box-shadow 0.3s ease-in-out;
        }

        .top-nav.scrolled {
            padding: 10px 0;
            background: rgba(255, 255, 255, 0.9);
            box-shadow: 0 6px 25px rgba(0, 0, 0, 0.08);
        }

        .nav-container {
            width: 100%;
            max-width: 1250px;
            margin: 0 auto;
            padding: 0 25px;
            display: flex; /* Use flex to align logo and nav */
            justify-content: space-between; /* Space between logo and nav */
            align-items: center; /* Vertically center items */
        }

        .logo {
            display: flex; /* Inline alignment of image and text */
            align-items: left; /* Center vertically */
            gap: 15px; /* Space between image and text */
        }

        .logo img {
            height: 50px;
            transition: height 0.3s ease-in-out;
            border-radius: 50%;
            box-shadow: 0 3px 8px rgba(0,0,0,0.1);
        }

        .top-nav.scrolled .logo img {
            height: 40px;
        }

        .logo-text {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-dark);
            transition: font-size 0.3s ease-in-out;
        }

        .top-nav.scrolled .logo-text {
            font-size: 1.3rem;
        }

        .main-nav ul {
            list-style: none;
            display: flex;
            gap: 25px;
            padding-left: 0;
            justify-content: flex-end; /* Align nav links to the right */
            flex-wrap: wrap;
        }

        .main-nav a {
            color: var(--dark);
            text-decoration: none;
            font-weight: 500;
            padding: 10px 18px;
            border-radius: 8px;
            transition: var(--transition-smooth);
            position: relative;
            font-size: 1rem;
            display: inline-block; /* Ensure padding works */
        }

        .main-nav a::after {
            content: '';
            position: absolute;
            bottom: 0px;
            left: 50%;
            transform: translateX(-50%);
            width: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            transition: width 0.3s ease-out;
            border-radius: 2px;
        }

        .main-nav a:hover::after {
            width: 60%;
        }

        .main-nav a:hover {
            color: var(--primary);
            background-color: rgba(74, 144, 226, 0.08);
        }

        .main-nav a.nav-button {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            box-shadow: 0 5px 18px rgba(74, 144, 226, 0.3);
            padding: 10px 25px;
            border-radius: 50px;
            font-weight: 600;
        }

        .main-nav a.nav-button:hover {
            background: linear-gradient(135deg, var(--primary-dark), var(--primary));
            color: white;
            transform: translateY(-3px) scale(1.03);
            box-shadow: 0 8px 25px rgba(74, 144, 226, 0.45);
            background-color: transparent;
        }

        .main-nav a.nav-button::after {
            display: none;
        }

        /* --- Enhanced Hero Section --- */
        .hero-section {
            height: 100vh;
            /* !!! IMPORTANT: Replace with your actual HIGH-QUALITY background image !!! */
            background: linear-gradient(135deg, rgba(44, 62, 80, 0.88), rgba(58, 123, 213, 0.8)), url('images/cms.jpg') no-repeat center center fixed; /* Fixed background for subtle parallax */
            background-size: cover;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            color: white;
            padding: 20px;
            padding-top: 100px; /* Adjusted padding-top to account for navbar */
            position: relative;
            overflow: hidden;
        }

        .hero-content {
            max-width: 900px;
            position: relative;
            z-index: 2;
        }

        .hero-content h1 {
            font-size: 4.2rem;
            font-weight: 800;
            margin-bottom: 30px;
            line-height: 1.25;
            text-shadow: 3px 3px 15px rgba(0, 0, 0, 0.5);
            animation: fadeInDown 1.2s cubic-bezier(0.23, 1, 0.32, 1) 0.2s backwards;
        }

        .hero-content p {
            font-size: 1.3rem;
            margin-bottom: 50px;
            opacity: 0.9;
            font-weight: 300;
            animation: fadeIn 1.5s ease-out 0.6s backwards;
            max-width: 750px;
            margin-left: auto;
            margin-right: auto;
        }

        .cta-buttons {
            animation: fadeInUp 1.2s cubic-bezier(0.23, 1, 0.32, 1) 1s backwards;
        }

        .cta-buttons .btn {
            padding: 16px 40px;
            font-size: 1.1rem;
            font-weight: 600;
            margin: 0 15px;
            border-radius: 50px;
            transition: transform 0.3s ease, box-shadow 0.3s ease, background-color 0.3s ease, color 0.3s ease;
            text-decoration: none;
            display: inline-block;
            border: 2px solid transparent;
            position: relative;
            overflow: hidden;
            cursor: pointer;
            z-index: 1;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .btn-primary {
            background: var(--secondary);
            color: var(--dark);
            border-color: var(--secondary);
            box-shadow: 0 6px 20px rgba(80, 227, 194, 0.4);
        }

        .btn-primary:hover {
            background-color: var(--white);
            color: var(--secondary);
            transform: translateY(-5px) scale(1.05);
            box-shadow: 0 10px 30px rgba(80, 227, 194, 0.55);
        }

        .btn-secondary {
            background-color: transparent;
            color: white;
            border-color: white;
            box-shadow: 0 5px 18px rgba(255, 255, 255, 0.2);
        }

        .btn-secondary:hover {
            background-color: white;
            color: var(--primary);
            border-color: white;
            transform: translateY(-5px) scale(1.05);
            box-shadow: 0 10px 30px rgba(255, 255, 255, 0.35);
        }

        /* Floating bubbles animation - Refined */
        .bubbles {
            position: absolute;
            width: 100%;
            height: 100%;
            top: 0;
            left: 0;
            z-index: 1;
            overflow: hidden;
            pointer-events: none;
        }

        .bubble {
            position: absolute;
            bottom: -200px;
            background: rgba(255, 255, 255, 0.12);
            border-radius: 50%;
            backdrop-filter: blur(4px);
            animation: float linear infinite;
            box-shadow: inset 0 0 12px rgba(255, 255, 255, 0.25);
            opacity: 0;
        }

        @keyframes float {
            0% {
                transform: translateY(0) rotate(0deg);
                opacity: 0;
            }
            10%, 90% {
                opacity: 0.9;
            }
            100% {
                transform: translateY(-115vh) rotate(720deg);
                opacity: 0;
            }
        }

        /* --- Enhanced Features Section --- */
        .features-section {
            padding: 130px 25px;
            background-color: #f1f5f8;
            position: relative;
            overflow: hidden;
        }

        .section-title {
            text-align: center;
            font-size: 2.8rem;
            font-weight: 700;
            color: var(--primary-dark);
            margin-bottom: 80px;
            position: relative;
        }

        .section-title::after {
            content: '';
            position: absolute;
            bottom: -25px;
            left: 50%;
            transform: translateX(-50%);
            width: 90px;
            height: 6px;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            border-radius: 3px;
            opacity: 0;
            animation: stretchUnderline 1s 0.5s ease-out forwards;
        }

        @keyframes stretchUnderline {
            from { width: 0; opacity: 0.5; }
            to { width: 90px; opacity: 1; }
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 40px;
            max-width: 1250px;
            margin: 0 auto;
        }

        /* Enhanced Glassmorphism Card */
        .glass-card {
            background: var(--card-bg);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
            border-radius: var(--radius);
            border: 1px solid var(--glass-border);
            border-top-color: rgba(255, 255, 255, 0.5);
            border-left-color: rgba(255, 255, 255, 0.5);
            box-shadow: var(--shadow);
            padding: 40px 35px;
            text-align: center;
            transition: transform 0.35s ease-out, box-shadow 0.35s ease-out, background-color 0.35s ease-out;
            transform-style: preserve-3d;
            opacity: 0;
            transform: translateY(30px);
        }

        .glass-card:hover {
            transform: translateY(-12px) scale(1.04) rotateX(5deg) rotateY(-3deg);
            box-shadow: var(--shadow-hover);
            background: rgba(255, 255, 255, 0.65);
        }

        .feature-icon {
            font-size: 3.5rem;
            margin-bottom: 30px;
            line-height: 1;
            display: inline-block;
            transition: transform 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275), color 0.3s ease;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            filter: drop-shadow(0 3px 6px rgba(80, 227, 194, 0.2)); /* Subtle default shadow */
        }

        .glass-card:hover .feature-icon {
            transform: scale(1.2) rotate(-10deg);
            filter: drop-shadow(0 5px 10px rgba(80, 227, 194, 0.4));
        }

        .feature-card h3 { /* Applies to glass-card */
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 18px;
            color: var(--primary-dark);
            transition: color 0.3s ease;
        }

        .glass-card:hover h3 {
            color: var(--primary);
        }

        .feature-card p { /* Applies to glass-card */
            font-size: 1rem;
            color: var(--grey);
            margin-bottom: 0;
            line-height: 1.65;
        }

        /* Subtle Background Pattern for Features Section */
        .features-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            /* background-image: url('assets/images/subtle-pattern.png'); */
            /* background-repeat: repeat; */
            opacity: 0.03;
            z-index: 0;
            pointer-events: none;
        }

        /* --- Enhanced Footer --- */
        footer {
            background-color: var(--dark);
            color: rgba(255, 255, 255, 0.85);
            padding: 60px 25px; /* Adjusted padding, removed extra top padding */
            text-align: center;
            position: relative;
        }

        .footer-container {
            max-width: 1250px;
            margin: 0 auto;
            position: relative;
            z-index: 2;
        }

        .footer-links {
            margin-bottom: 45px; /* Slightly more space */
        }

        .footer-links a {
            color: var(--secondary);
            text-decoration: none;
            margin: 0 20px;
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
            left: 0;
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
            width: 100%;
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

        /* Animations */
        @keyframes fadeInDown {
            from { opacity: 0; transform: translate3d(0, -50px, 0); }
            to { opacity: 1; transform: translate3d(0, 0, 0); }
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translate3d(0, 50px, 0); }
            to { opacity: 1; transform: translate3d(0, 0, 0); }
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        /* Responsive Adjustments */
        @media (max-width: 992px) {
            .nav-container { padding: 0 20px; }
            .hero-section { padding-top: 120px; } /* Adjust hero padding for navbar */
            .hero-content h1 { font-size: 3.5rem; }
            .hero-content p { font-size: 1.2rem; }
            .features-grid { grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 30px;}
            .glass-card:hover {
                transform: translateY(-8px) scale(1.03);
            }
            .footer-links a { margin: 0 15px; }
            footer { padding: 50px 20px; }
        }

        @media (max-width: 768px) {
            .top-nav { padding: 12px 0; background: rgba(255, 255, 255, 0.85); }
            .top-nav.scrolled { padding: 8px 0; background: rgba(255, 255, 255, 0.95); }
            .nav-container { padding: 0 15px; flex-direction: column; align-items: flex-start; }
            .logo { gap: 8px; }
            .logo img { height: 40px; }
            .top-nav.scrolled .logo img { height: 35px; }
            .logo-text { font-size: 1.3rem; }
            .top-nav.scrolled .logo-text { font-size: 1.2rem; }
            .main-nav { margin-top: 10px; width: 100%; }
            .main-nav ul { justify-content: center; gap: 15px; }
            .main-nav a { font-size: 0.95rem; padding: 8px 12px;}
            .main-nav a.nav-button { padding: 8px 20px;}
            .hero-section { min-height: auto; padding-top: 100px; padding-bottom: 60px; background-attachment: scroll; }
            .hero-content h1 { font-size: 2.8rem; }
            .hero-content p { font-size: 1.05rem; margin-bottom: 40px;}
            .cta-buttons .btn { font-size: 1rem; padding: 14px 30px; margin: 10px; }
            .section-title { font-size: 2.2rem; margin-bottom: 60px;}
            .section-title::after { width: 70px; height: 5px; bottom: -20px; }
            @keyframes stretchUnderline {
                from { width: 0; opacity: 0.5; }
                to { width: 70px; opacity: 1; }
            }
            .features-section { padding: 90px 15px; }
            .glass-card { padding: 30px 25px; }
            .glass-card:hover {
                transform: translateY(-6px) scale(1.02);
            }
            .footer-links a { margin: 0 10px; font-size: 0.95rem;}
            .social-links a { margin: 0 12px; font-size: 1.6rem;}
            footer { padding: 40px 15px;}
        }

        @media (max-width: 480px) {
            .hero-section { padding-top: 90px; }
            .hero-content h1 { font-size: 2.2rem; }
            .hero-content p { font-size: 1rem; margin-bottom: 35px;}
            .cta-buttons { display: flex; flex-direction: column; gap: 15px; align-items: center;}
            .cta-buttons .btn { width: 85%; max-width: 320px; padding: 15px 25px; font-size: 0.95rem;}
            .section-title { font-size: 1.9rem;}
            .section-title::after { width: 60px; height: 4px; }
            @keyframes stretchUnderline {
                from { width: 0; opacity: 0.5; }
                to { width: 60px; opacity: 1; }
            }
            .features-grid { gap: 25px; grid-template-columns: 1fr; }
            .glass-card { padding: 25px 20px; }
            .feature-icon { font-size: 3rem; margin-bottom: 25px; }
            .feature-card h3 { font-size: 1.3rem; }
            .footer-links a { display: block; margin: 12px auto; }
            .social-links { margin: 35px 0;}
 junction {
    display: flex;
    justify-content: center;
    gap: 15px;
    flex-wrap: wrap;
}

.footer-links a {
    color: var(--secondary);
    text-decoration: none;
    font-weight: 500;
    transition: color 0.3s ease;
}

.footer-links a:hover {
    color: var(--white);
}

.social-links {
    margin: 20px 0;
}

.social-links a {
    color: rgba(255, 255, 255, 0.75);
    font-size: 1.5rem;
    margin: 0 10px;
    transition: color 0.3s ease, transform 0.3s ease;
}

.social-links a:hover {
    color: var(--secondary);
    transform: translateY(-3px);
}

.copyright {
    font-size: 0.9rem;
    color: var(--grey);
    margin-top: 20px;
}

/* Responsive Adjustments */
@media (max-width: 768px) {
    .nav-container {
        flex-direction: column;
        align-items: flex-start;
    }

    .main-nav {
        margin-top: 10px;
    }

    .main-nav ul {
        flex-direction: column;
        align-items: center;
        width: 100%;
    }

    .main-nav a {
        padding: 8px 16px;
    }

    .hero-content h1 {
        font-size: 2.5rem;
    }

    .hero-content p {
        font-size: 1rem;
    }

    .cta-buttons .btn {
        padding: 12px 30px;
        font-size: 0.9rem;
    }

    .features-grid {
        grid-template-columns: 1fr;
    }

    .glass-card {
        padding: 20px;
    }

    .section-title {
        font-size: 2rem;
    }
}

@media (max-width: 480px) {
    .logo img {
        height: 35px;
    }

    .logo-text {
        font-size: 1.2rem;
    }

    .hero-content h1 {
        font-size: 2rem;
    }

    .cta-buttons {
        flex-direction: column;
        gap: 10px;
    }

    .cta-buttons .btn {
        width: 100%;
        max-width: 280px;
    }

    .section-title {
        font-size: 1.8rem;
    }

    .feature-icon {
        font-size: 2.5rem;
    }

    .feature-card h3 {
        font-size: 1.2rem;
    }
}
.logo{
    padding:left;
    align:left;
}
    </style>
</head>
<body>
    <!-- Top Navigation -->
    <nav class="top-nav" id="topNav">
        <div class="nav-container">
            <div class="logo">
                <!-- !!! IMPORTANT: Replace with your actual high-resolution logo image !!! -->
                <img src="images/logo.jpg" alt="DMU Logo">
                <span class="logo-text">DEBRE MARKOS UNIVERSITY COMPLAINT MANAGEMENT SYSTEM</span>
            </div>
            <!-- Navigation Links -->
            <div class="main-nav">
                <ul>
                    <li><a href="index.php">Home</a></li>
                    <li><a href="#features">Features</a></li>
                    <li><a href="contact.php">Contact</a></li>
                    <li><a href="help.php">Help</a></li>
                    <li><a href="login.php" class="nav-button">Login</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero-section">
        <!-- Floating bubbles background -->
        <div class="bubbles" id="bubbles"></div>
        <div class="hero-content">
            <h1>Streamlined Complaint Management at DMU</h1>
            <p>
                Submit, track, and resolve issues efficiently. A dedicated platform ensuring your voice is heard at Debre Markos University.
            </p>
            <div class="cta-buttons">
                <a href="login.php" class="btn btn-primary">Access Account</a>
                <a href="user/create_account.php" class="btn btn-secondary">Register Now</a>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section id="features" class="features-section">
        <h2 class="section-title">Why Use Our System?</h2>
        <div class="features-grid">
            <!-- Cards will be animated by JS Intersection Observer -->
            <div class="feature-card glass-card">
                <div class="feature-icon"><i class="fas fa-paper-plane"></i></div>
                <h3>Easy Submission</h3>
                <p>Quickly submit your complaints through a simple and intuitive online form.</p>
            </div>
            <div class="feature-card glass-card">
                <div class="feature-icon"><i class="fas fa-tasks"></i></div>
                <h3>Track Progress</h3>
                <p>Monitor the status and updates of your submitted complaints in real-time.</p>
            </div>
            <div class="feature-card glass-card">
                <div class="feature-icon"><i class="fas fa-shield-alt"></i></div>
                <h3>Confidential & Secure</h3>
                <p>Your submissions are handled with utmost security and confidentiality options.</p>
            </div>
            <div class="feature-card glass-card">
                <div class="feature-icon"><i class="fas fa-headset"></i></div>
                <h3>Efficient Handling</h3>
                <p>Dedicated handlers review and address complaints promptly for timely resolution.</p>
            </div>
            <div class="feature-card glass-card">
                <div class="feature-icon"><i class="fas fa-bell"></i></div>
                <h3>Notifications</h3>
                <p>Receive notifications about status changes and responses to your complaints.</p>
            </div>
            <div class="feature-card glass-card">
                <div class="feature-icon"><i class="fas fa-comments"></i></div>
                <h3>Direct Communication</h3>
                <p>Communicate directly with handlers regarding your specific complaint if needed.</p>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer>
        <div class="footer-container">
            <div class="footer-links">
                <a href="index.php">Home</a>
                <a href="#features">Features</a>
                <a href="about.php">About</a>
                <a href="contact.php">Contact</a>
                <a href="help.php">Help</a>
                <a href="#">FAQ</a>
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

    <script>
        // Function to create floating bubbles with refined animation
        function createBubbles() {
            const bubblesContainer = document.getElementById('bubbles');
            if (!bubblesContainer) return;
            const bubbleCount = 25;

            for (let i = 0; i < bubbleCount; i++) {
                const bubble = document.createElement('div');
                bubble.classList.add('bubble');

                const size = Math.random() * 70 + 20;
                bubble.style.width = `${size}px`;
                bubble.style.height = `${size}px`;
                bubble.style.left = `${Math.random() * 100}%`;

                const duration = Math.random() * 20 + 15;
                const delay = Math.random() * 15;

                bubble.style.animationDuration = `${duration}s`;
                bubble.style.animationDelay = `${delay}s`;

                bubblesContainer.appendChild(bubble);
            }
        }

        // Function for Navbar scroll effect with debouncing
        function setupNavbar() {
            const navbar = document.getElementById('topNav');
            if (!navbar) return;

            let lastScrollY = window.scrollY;
            let ticking = false;

            const updateNavbar = () => {
                if (window.scrollY > 60) {
                    navbar.classList.add('scrolled');
                } else {
                    navbar.classList.remove('scrolled');
                }
                ticking = false;
            };

            window.addEventListener('scroll', () => {
                lastScrollY = window.scrollY;
                if (!ticking) {
                    window.requestAnimationFrame(updateNavbar);
                    ticking = true;
                }
            });
            updateNavbar();
        }

        // Function to animate feature cards on scroll
        function setupFeatureCardAnimation() {
            const featureCards = document.querySelectorAll('.feature-card.glass-card');
            if (featureCards.length === 0) return;

            const observerOptions = {
                root: null,
                rootMargin: '0px',
                threshold: 0.15
            };

            const observerCallback = (entries, observer) => {
                entries.forEach((entry, index) => {
                    if (entry.isIntersecting) {
                        const delay = index * 100;
                        entry.target.style.transition = `opacity 0.6s ease-out ${delay}ms, transform 0.6s cubic-bezier(0.23, 1, 0.32, 1) ${delay}ms`;
                        entry.target.style.opacity = '1';
                        entry.target.style.transform = 'translateY(0)';
                        observer.unobserve(entry.target);
                    }
                });
            };

            const observer = new IntersectionObserver(observerCallback, observerOptions);

            featureCards.forEach(card => {
                observer.observe(card);
            });
        }

        // Initialize effects after DOM is loaded
        document.addEventListener('DOMContentLoaded', () => {
            createBubbles();
            setupNavbar();
            setupFeatureCardAnimation();
        });
    </script>
</body>
</html>