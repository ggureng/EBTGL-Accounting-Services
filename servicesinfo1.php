<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Our Services - EBTGL Accounting Services</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <!-- Removed Poppins font import -->
  <style>
    :root {
      /* Updated color palette */
      --primary: #005B96;
      --primary-dark: #004a7a;
      --primary-blue: #005B96;
      --light-blue: #F0F0F0;
      --dark-blue: #003355;
      --primary-light: #4a7ea9;
      --accent: #F0F0F0;
      --accent-light: #f8f8f8;
      --light: #FFFFFF;
      --dark: #1A1A1A;
      --gray: #666666;
      --light-gray: #CCCCCC;
      --success: #2ecc71;
      --error: #e74c3c;
      --shadow-sm: 0 4px 6px rgba(0,0,0,0.05);
      --shadow-md: 0 6px 12px rgba(0,0,0,0.1);
      --shadow-lg: 0 15px 30px rgba(0,0,0,0.15);
      --radius: 10px;
      --radius-lg: 16px;
      --transition: all 0.3s ease;
    }

    /* Updated font stack */
    body, html {
      font-family: Verdana, Tahoma, Geneva, sans-serif;
    }

    /* Heading sizes */
    h1 { font-size: 2rem; }     /* 32px */
    h2 { font-size: 1.75rem; }  /* 28px */
    h3 { font-size: 1.5rem; }   /* 24px */
    h4 { font-size: 1.25rem; }  /* 20px */
    h5 { font-size: 1.125rem; } /* 18px */
    h6 { font-size: 1rem; }     /* 16px */

    /* Adjust specific heading sizes */
    .hero h1 {
      font-size: 2.5rem; /* Prominent but not oversized */
    }

    .section-title h2 {
      font-size: 2rem; /* 32px */
    }

    /* Button font sizes */
    .hero-btn, .btn {
      font-size: 1.125rem; /* 18px */
    }

    /* Base text size */
    body {
      font-size: 1rem; /* 16px */
    }

    /* Additional adjustments */
    .logo span {
      font-weight: bold; /* Remove bold from logo */
    }

    body, html {
      margin: 0;
      padding: 0;
      scroll-behavior: smooth;
      background-color: #f2f2f2;
      color: var(--dark);
      line-height: 1.6;
    }

    nav {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 15px 30px;
      background-color: var(--primary);
      position: sticky;
      top: 0;
      z-index: 1000;
      box-shadow: var(--shadow-sm);
    }

    .logo {
      color: white;
      font-weight: 700;
      font-size: 24px;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .logo img {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      margin-right: 12px;
      border: 2px solid white;
    }

    .logo i {
      font-size: 22px;
    }

    .nav-links {
      display: flex;
      align-items: center;
      gap: 15px;
    }

    .nav-links a, .nav-links button {
      color: #fff;
      text-decoration: none;
      font-weight: 500;
      background: none;
      border: none;
      font-size: 16px;
      cursor: pointer;
      padding: 8px 12px;
      border-radius: 6px;
      transition: var(--transition);
    }

    .nav-links a:hover, .nav-links button:hover {
      background-color: rgba(255,255,255,0.15);
      text-decoration: none;
    }

    .dropdown {
      position: relative;
      display: inline-block;
    }

    .dropbtn {
      background-color: transparent;
      color: white;
      padding: 8px 12px;
      font-size: 16px;
      border: none;
      cursor: pointer;
      font-weight: 500;
      border-radius: 6px;
      display: flex;
      align-items: center;
      gap: 6px;
      transition: var(--transition);
    }

    .dropbtn:hover {
      background-color: rgba(255,255,255,0.15);
    }

    .dropdown-content {
      display: none;
      position: absolute;
      right: 0;
      background-color: white;
      min-width: 200px;
      box-shadow: var(--shadow-md);
      z-index: 1;
      border-radius: var(--radius);
      overflow: hidden;
      margin-top: 8px;
      transition: var(--transition);
    }

    .dropdown-content a {
      color: var(--dark);
      padding: 12px 16px;
      text-decoration: none;
      display: block;
      transition: var(--transition);
      font-weight: 500;
      border-bottom: 1px solid var(--light-gray);
    }

    .dropdown-content a:hover {
      background-color: var(--accent-light);
      color: var(--primary-dark);
    }

    .dropdown-content a:last-child {
      border-bottom: none;
    }

    .dropdown:hover .dropdown-content {
      display: block;
    }

    .hero {
      background: linear-gradient(135deg, rgba(0,91,150,0.85) 0%, rgba(0,74,122,0.9) 100%), url('images/services-bg.jpg') center/cover no-repeat;
      height: 90vh;
      color: white;
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      text-align: center;
      padding: 0 20px;
      position: relative;
    }

    .hero-content {
      max-width: 800px;
      animation: fadeInUp 1s ease-out;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      text-align: center;
    }

    .hero h1 {
      font-size: 3.5rem;
      margin-bottom: 20px;
      font-weight: 700;
      text-shadow: 0 2px 8px rgba(0,0,0,0.2);
    }

    .hero p {
      font-size: 1.5rem;
      margin-bottom: 40px;
      max-width: 700px;
      font-weight: 300;
      text-shadow: 0 1px 4px rgba(0,0,0,0.2);
    }

    .section {
      padding: 80px 20px;
      max-width: 1200px;
      margin: auto;
    }

    .section-title {
      text-align: center;
      margin-bottom: 60px;
    }

    .section-title h2 {
      font-size: 2.5rem;
      color: var(--primary-dark);
      margin-bottom: 15px;
      position: relative;
      display: inline-block;
    }

    .section-title h2:after {
      content: '';
      position: absolute;
      bottom: -10px;
      left: 50%;
      transform: translateX(-50%);
      width: 80px;
      height: 4px;
      background: var(--primary);
      border-radius: 2px;
    }

    .section-title p {
      font-size: 1.2rem;
      color: var(--gray);
      max-width: 700px;
      margin: 20px auto 0;
    }

    .info-section {
      display: flex;
      flex-direction: column;
      gap: 70px;
    }

    .info-block {
      display: flex;
      flex-direction: row;
      align-items: center;
      gap: 40px;
      padding: 30px;
      background: white;
      border-radius: var(--radius-lg);
      box-shadow: var(--shadow-sm);
      transition: var(--transition);
    }

    .info-block:hover {
      transform: translateY(-5px);
      box-shadow: var(--shadow-md);
    }

    .info-block:nth-child(even) {
      flex-direction: row-reverse;
    }

    .info-block img {
      width: 45%;
      border-radius: var(--radius);
      aspect-ratio: 16/9;
      object-fit: cover;
      box-shadow: var(--shadow-sm);
    }

    .info-block-content {
      width: 55%;
      text-align: justify;
    }

    .info-block-content h2 {
      font-size: 1.8rem;
      color: var(--primary-dark);
      margin-bottom: 20px;
    }

    .info-block-content p {
      font-size: 1.1rem;
      color: var(--dark);
      line-height: 1.8;
    }

    /* Chatbox Styles */
    .chat-toggle {
      position: fixed;
      bottom: 30px;
      right: 30px;
      background-color: var(--primary);
      color: white;
      width: 60px;
      height: 60px;
      border-radius: 50%;
      cursor: pointer;
      z-index: 1999;
      font-size: 24px;
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: var(--shadow-md);
      transition: var(--transition);
    }

    .chat-toggle:hover {
      background-color: var(--primary-dark);
      transform: scale(1.1);
    }

    .chatbox {
      position: fixed;
      bottom: 100px;
      right: 30px;
      width: 350px;
      max-height: 500px;
      background: white;
      border-radius: var(--radius-lg);
      display: none;
      flex-direction: column;
      z-index: 2000;
      box-shadow: var(--shadow-lg);
      overflow: hidden;
      transform: translateY(20px);
      opacity: 0;
      transition: var(--transition);
    }

    .chatbox.open {
      display: flex;
      animation: fadeInUp 0.3s ease-out forwards;
    }

    .chat-header {
      background-color: var(--primary);
      color: white;
      padding: 20px;
      font-weight: 600;
      font-size: 1.2rem;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .chat-header i {
      cursor: pointer;
      font-size: 1.3rem;
      opacity: 0.8;
      transition: var(--transition);
    }

    .chat-header i:hover {
      opacity: 1;
    }

    .chat-messages {
      flex: 1;
      padding: 20px;
      overflow-y: auto;
      background: var(--light);
      display: flex;
      flex-direction: column;
      gap: 15px;
      height: 300px;
    }

    .chat-input {
      display: flex;
      border-top: 1px solid var(--light-gray);
      background: white;
      padding: 15px;
    }

    .chat-input input {
      flex: 1;
      padding: 12px 15px;
      border: 1px solid var(--light-gray);
      border-radius: 50px;
      font-size: 1rem;
      outline: none;
      transition: var(--transition);
    }

    .chat-input input:focus {
      border-color: var(--primary-light);
      box-shadow: 0 0 0 3px rgba(70, 130, 180, 0.15);
      outline: none;
    }

    .chat-input button {
      background: var(--primary);
      color: white;
      border: none;
      width: 45px;
      height: 45px;
      border-radius: 50%;
      cursor: pointer;
      margin-left: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: var(--transition);
    }

    .chat-input button:hover {
      background: var(--primary-dark);
      transform: scale(1.05);
    }

    .message-bubble {
      max-width: 80%;
      padding: 12px 18px;
      border-radius: 20px;
      font-size: 0.95rem;
      line-height: 1.5;
      word-wrap: break-word;
      position: relative;
      animation: fadeIn 0.3s ease-out;
    }

    .message-bubble.you {
      background-color: var(--primary);
      color: white;
      align-self: flex-end;
      border-bottom-right-radius: 5px;
    }

    .message-bubble.admin {
      background-color: var(--accent-light);
      color: var(--dark);
      align-self: flex-start;
      border-bottom-left-radius: 5px;
    }

    /* Modal Styles */
    .modal {
      display: none;
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background-color: rgba(0,0,0,0.7);
      z-index: 3000;
      justify-content: center;
      align-items: center;
    }

    .modal-content {
      background-color: white;
      padding: 40px;
      border-radius: var(--radius-lg);
      width: 90%;
      max-width: 500px;
      box-shadow: var(--shadow-lg);
      position: relative;
      animation: modalFadeIn 0.4s ease-out;
      max-height: 85vh;
      overflow-y: auto;
    }

    .close-modal {
      position: absolute;
      top: 20px;
      right: 20px;
      font-size: 24px;
      cursor: pointer;
      color: var(--gray);
      transition: var(--transition);
    }

    .close-modal:hover {
      color: var(--dark);
      transform: rotate(90deg);
    }

    .modal h2 {
      margin-bottom: 30px;
      color: var(--primary);
      font-size: 1.8rem;
      text-align: center;
    }

    .form-group {
      margin-bottom: 25px;
    }

    .form-group label {
      display: block;
      margin-bottom: 10px;
      font-weight: 600;
      color: var(--dark);
      font-size: 0.95rem;
    }

    .form-group input {
      width: 100%;
      padding: 14px;
      border: 1px solid var(--light-gray);
      border-radius: 8px;
      font-size: 1rem;
      transition: var(--transition);
    }

    .form-group input:focus {
      border-color: var(--primary-light);
      box-shadow: 0 0 0 3px rgba(70, 130, 180, 0.15);
      outline: none;
    }

    .form-actions {
      display: flex;
      justify-content: center;
      margin-top: 30px;
    }

    .btn {
      padding: 14px 32px;
      border: none;
      border-radius: 50px;
      cursor: pointer;
      font-size: 1rem;
      font-weight: 600;
      transition: var(--transition);
      box-shadow: var(--shadow-sm);
    }

    .btn-primary {
      background-color: var(--primary);
      color: white;
    }

    .btn-primary:hover {
      background-color: var(--primary-dark);
      transform: translateY(-2px);
      box-shadow: var(--shadow-md);
    }

    .notification {
      position: fixed;
      top: 30px;
      right: 30px;
      padding: 18px 28px;
      border-radius: var(--radius);
      color: white;
      font-weight: 600;
      z-index: 4000;
      display: none;
      animation: fadeInOut 3s ease-in-out;
      box-shadow: var(--shadow-md);
      max-width: 400px;
    }

    .notification.success {
      background-color: var(--success);
    }

    .notification.error {
      background-color: var(--error);
    }

    footer {
      background: linear-gradient(135deg, var(--primary-dark), var(--primary));
      color: white;
      padding: 60px 20px 30px;
      text-align: center;
    }

    .footer-content {
      max-width: 1200px;
      margin: 0 auto;
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
      gap: 40px;
      text-align: left;
    }

    .footer-column h3 {
      font-size: 1.4rem;
      margin-bottom: 20px;
      position: relative;
      display: inline-block;
    }

    .footer-column h3:after {
      content: '';
      display: block;
      width: 40px;
      height: 3px;
      background: var(--light-blue);
      margin-top: 10px;
    }

    .footer-column p, .footer-column a {
      color: rgba(255, 255, 255, 0.9);
      margin-bottom: 10px;
      display: block;
      text-decoration: none;
      transition: all 0.3s ease;
    }

    .footer-column a:hover {
      color: white;
      transform: translateX(5px);
    }

    .social-links {
      display: flex;
      gap: 15px;
      margin-top: 20px;
    }

    .social-links a {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      background: rgba(255, 255, 255, 0.1);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.2rem;
    }

    .social-links a:hover {
      background: var(--light-blue);
      transform: translateY(-5px);
    }

    .copyright {
      margin-top: 40px;
      padding-top: 20px;
      border-top: 1px solid rgba(255, 255, 255, 0.1);
      font-size: 0.9rem;
      color: rgba(255, 255, 255, 0.7);
    }

    /* Animations */
    @keyframes fadeInUp {
      from {
        opacity: 0;
        transform: translateY(20px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    @keyframes fadeIn {
      from {
        opacity: 0;
      }
      to {
        opacity: 1;
      }
    }

    @keyframes modalFadeIn {
      from {
        opacity: 0;
        transform: translateY(-30px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    @keyframes fadeInOut {
      0%, 100% { opacity: 0; transform: translateY(-20px); }
      10%, 90% { opacity: 1; transform: translateY(0); }
    }

    /* Back to top button styles - ADDED FROM INDEX1.HTML */
    .back-to-top {
      position: fixed;
      bottom: 30px;
      left: 50%;
      transform: translateX(-50%);
      width: 40px;
      height: 40px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--primary), var(--primary-dark));
      color: white;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 16px;
      cursor: pointer;
      box-shadow: var(--shadow-md);
      transition: var(--transition);
      z-index: 10000;
      opacity: 0;
      visibility: hidden;
      transform: translate(-50%, 20px);
    }
    
    .back-to-top.show {
      opacity: 1;
      visibility: visible;
      transform: translate(-50%, 0);
    }
    
    .back-to-top:hover {
      transform: translate(-50%, -5px);
      box-shadow: var(--shadow-lg);
    }

    /* Responsive adjustments */
    @media (max-width: 900px) {
      .info-block, .info-block:nth-child(even) {
        flex-direction: column;
      }
      
      .info-block img, .info-block-content {
        width: 100%;
      }
      
      .hero h1 {
        font-size: 2.8rem;
      }
      
      .hero p {
        font-size: 1.2rem;
      }
    }

    @media (max-width: 768px) {
      nav {
        padding: 12px 20px;
        flex-wrap: wrap;
      }
      
      .nav-links {
        gap: 8px;
      }
      
      .chatbox {
        width: 90%;
        right: 5%;
        bottom: 80px;
      }
      
      .hero h1 {
        font-size: 2.2rem;
      }
      
      .logo span {
        display: none; /* Hide text on mobile */
      }
      
      .logo img {
        margin-right: 0;
      }
    }

    @media (max-width: 480px) {
      .section-title h2 {
        font-size: 2rem;
      }
      
      .back-to-top {
        bottom: 80px; /* Adjust position to not interfere with chat button */
      }
    }

    .file-attachment {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 5px 10px;
      background-color: #f0f7ff;
      border-radius: 20px;
      margin-top: 5px;
      font-size: 14px;
    }
    
    .file-attachment a {
      color: var(--primary);
      text-decoration: none;
    }
    
    .file-attachment a:hover {
      text-decoration: underline;
    }
    
    .remove-file {
      color: #e74c3c;
      cursor: pointer;
    }
  </style>
</head>
<body>
<!-- Notification element -->
<div class="notification" id="notification"></div>

<!-- Edit Account Modal -->
<div class="modal" id="editAccountModal">
  <div class="modal-content">
    <span class="close-modal" onclick="closeModal()">&times;</span>
    <h2>Edit Account</h2>
    
    <form id="accountForm">
      <div class="form-group">
        <label for="company_name">Company Name</label>
        <input type="text" id="company_name" name="company_name" required>
      </div>
      
      <div class="form-group">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" required>
      </div>
      
      <div class="form-group">
        <label for="phone">Phone</label>
        <input type="tel" id="phone" name="phone" required>
      </div>
      
      <div class="form-group">
        <label for="position">Position</label>
        <input type="text" id="position" name="position" required>
      </div>
      
      <div class="form-group">
        <label for="full_name">Full Name</label>
        <input type="text" id="full_name" name="full_name" required>
      </div>
      
      <div class="form-group">
        <label for="password">New Password (leave blank to keep current)</label>
        <input type="password" id="password" name="password">
      </div>
      
      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<nav>
  <div class="logo">
    <!-- Added logo image from index1.html -->
    <img src="images/NXT.png" alt="EBTGL Accounting Services">
    <span>EBTGL Accounting Services</span>
  </div>
  <div class="nav-links">
    <a href="index1.html"><i class="fas fa-home"></i> Home</a>
    <a href="servicesinfo1.php"><i class="fas fa-concierge-bell"></i> Services</a>
    <a href="about1.html"><i class="fas fa-info-circle"></i> About Us</a>
    <div class="dropdown">
      <button class="dropbtn" id="companyNameBtn">
        <i class="fas fa-building"></i>
        <span>Loading...</span>
        <i class="fas fa-caret-down"></i>
      </button>
      <div class="dropdown-content">
        <a href="#" onclick="openEditAccountModal(); return false;">
          <i class="fas fa-user-edit"></i> Edit Account
        </a>
        <a href="#" onclick="logout(); return false;">
          <i class="fas fa-sign-out-alt"></i> Log Out
        </a>
      </div>
    </div>
  </div>
</nav>

<section class="hero">
  <div class="hero-content">
    <h1>Our Services</h1>
    <p>We offer essential financial services to help businesses thrive and grow.</p>
  </div>
</section>

<section class="section">
  <div class="section-title">
    <h2>Our Accounting Services</h2>
    <p>Professional solutions tailored to your business needs</p>
  </div>
  
  <div class="info-section">
    <div class="info-block">
      <img src="images/bookkeeping.jpg" alt="Bookkeeping">
      <div class="info-block-content">
        <h2>Bookkeeping</h2>
        <p>We provide accurate, timely, and organized bookkeeping solutions for your business, ensuring your financial records are always up to date and audit-ready. Our team meticulously tracks all financial transactions, maintains ledgers, and reconciles accounts to give you a clear picture of your financial health.</p>
      </div>
    </div>

    <div class="info-block">
      <img src="images/reporting.jpg" alt="Financial Reporting">
      <div class="info-block-content">
        <h2>Financial Reporting</h2>
        <p>Our financial reports offer insights into your business performance, enabling informed decisions and compliance with financial regulations. We prepare comprehensive balance sheets, income statements, and cash flow reports that highlight key performance indicators and financial trends in your business.</p>
      </div>
    </div>
</section>

<!-- Back to top button - ADDED FROM INDEX1.HTML -->
<div class="back-to-top" id="backToTop">
  <i class="fas fa-arrow-up"></i>
</div>

<div class="chat-toggle" onclick="toggleChat()">
  <i class="fas fa-comment-dots"></i>
</div>

<div class="chatbox" id="chatbox">
  <div class="chat-header">
    <span>Chat with Admin</span>
    <i class="fas fa-times" onclick="toggleChat()"></i>
  </div>
  <div class="chat-messages" id="chatMessages"></div>
  <div class="chat-input">
    <input type="text" id="chatInput" placeholder="Type your message...">
    <label for="fileAttachment" style="cursor: pointer; margin-left: 10px;">
      <i class="fas fa-paperclip"></i>
    </label>
    <input type="file" id="fileAttachment" style="display: none">
    <button onclick="sendMessage()">
      <i class="fas fa-paper-plane"></i>
    </button>
  </div>
  <div id="filePreview" style="padding: 5px 15px; background: white; border-top: 1px solid #eee;"></div>
</div>

<footer>
  <div class="footer-content">
    <div class="footer-column">
      <h3>EBTGL Accounting Services</h3>
      <p>Providing professional accounting services with accuracy, integrity, and personalized attention to help your business thrive.</p>
      <div class="social-links">
        <a href="https://www.facebook.com/p/EB-Tarobal-Co-CPAs-100063656455449/"><i class="fab fa-facebook-f"></i></a>
      </div>
    </div>
    
    <div class="footer-column">
      <h3>Quick Links</h3>
      <a href="index1.html">Home</a>
      <a href="servicesinfo1.php">Services</a>
      <a href="about1.html">About Us</a>
    </div>
    
    <div class="footer-column">
      <h3>Our Services</h3>
      <a href="servicesinfo1.php">Bookkeeping</a>
      <a href="servicesinfo1.php">Financial Reporting</a>
    </div>
    
    <div class="footer-column">
      <h3>Contact Us</h3>
      <p><i class="fas fa-map-marker-alt me-2"></i> Brgy Tibagan, San Juan City</p>
      <p><i class="fas fa-phone me-2"></i> (02) 8123-4567</p>
      <p><i class="fas fa-envelope me-2"></i> ebtgl5220712@gmail.com</p>
      <p><i class="fas fa-clock me-2"></i> Mon-Frid: 8AM - 5PM</p>
    </div>
  </div>
  
  <div class="copyright">
    &copy; 2025 EBTGL Accounting Services. All rights reserved.
  </div>
</footer>

<script>
let selectedFile = null;
let filePreviewVisible = false;

// ====================
// Back to Top Functionality - ADDED FROM INDEX1.HTML
// ====================
const backToTopBtn = document.getElementById('backToTop');

// Show button when user scrolls down
window.addEventListener('scroll', function() {
  const scrollPosition = window.scrollY;
  const pageHeight = document.documentElement.scrollHeight - window.innerHeight;
  const scrollPercentage = (scrollPosition / pageHeight) * 100;
  
  if (scrollPercentage > 20) {
    backToTopBtn.classList.add('show');
  } else {
    backToTopBtn.classList.remove('show');
  }
});

// Scroll to top when clicked
backToTopBtn.addEventListener('click', function() {
  window.scrollTo({
    top: 0,
    behavior: 'smooth'
  });
});

// Open modal and load account data
function openEditAccountModal() {
  fetch('get_client_data.php')
    .then(response => response.json())
    .then(data => {
      if(data.success) {
        document.getElementById('company_name').value = data.company_name || '';
        document.getElementById('email').value = data.email || '';
        document.getElementById('phone').value = data.phone || '';
        document.getElementById('position').value = data.position || '';
        document.getElementById('full_name').value = data.full_name || '';
        document.getElementById('editAccountModal').style.display = 'flex';
      } else {
        showNotification('Error loading account data', 'error');
      }
    })
    .catch(error => {
      console.error('Error:', error);
      showNotification('Error loading account data', 'error');
    });
}

// Close modal
function closeModal() {
  document.getElementById('editAccountModal').style.display = 'none';
}

// Handle form submission
document.getElementById('accountForm').addEventListener('submit', function(e) {
  e.preventDefault();
  
  const formData = {
    company_name: document.getElementById('company_name').value,
    email: document.getElementById('email').value,
    phone: document.getElementById('phone').value,
    position: document.getElementById('position').value,
    full_name: document.getElementById('full_name').value,
    password: document.getElementById('password').value
  };
  
  fetch('update_client.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json'
    },
    body: JSON.stringify(formData)
  })
  .then(response => response.json())
  .then(data => {
    if(data.success) {
      showNotification('Account updated successfully!', 'success');
      // Update UI with new company name
      document.getElementById('companyNameBtn').querySelector('span').textContent = formData.company_name;
      closeModal();
    } else {
      showNotification(data.message || 'Error updating account', 'error');
    }
  })
  .catch(error => {
    console.error('Error:', error);
    showNotification('Error updating account', 'error');
  });
});

// Notification system
function showNotification(message, type) {
  const notification = document.getElementById('notification');
  notification.textContent = message;
  notification.className = `notification ${type}`;
  notification.style.display = 'block';
  
  setTimeout(() => {
    notification.style.display = 'none';
  }, 3000);
}

// ====================
// Chat Functionality
// ====================

function toggleChat() {
  const chatbox = document.getElementById('chatbox');
  const isOpen = chatbox.classList.contains('open');
  
  if (isOpen) {
    chatbox.classList.remove('open');
    setTimeout(() => {
      chatbox.style.display = 'none';
    }, 300);
  } else {
    chatbox.style.display = 'flex';
    setTimeout(() => {
      chatbox.classList.add('open');
      document.getElementById('chatInput').focus();
    }, 10);
  }
}

document.getElementById('fileAttachment').addEventListener('change', function(e) {
  if (this.files.length > 0) {
    selectedFile = this.files[0];
    showFilePreview();
  }
});

function showFilePreview() {
  if (!selectedFile) return;
  
  const preview = document.getElementById('filePreview');
  preview.innerHTML = `
    <div class="file-attachment">
      <i class="fas fa-file"></i>
      <span>${selectedFile.name}</span>
      <span class="remove-file" onclick="removeFile()">
        <i class="fas fa-times"></i>
      </span>
    </div>
  `;
  filePreviewVisible = true;
}

function removeFile() {
  selectedFile = null;
  document.getElementById('fileAttachment').value = '';
  document.getElementById('filePreview').innerHTML = '';
  filePreviewVisible = false;
}

function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

function convertUrlsToLinks(text) {
  const urlRegex = /(https?:\/\/[^\s<]+)/g;
  return text.replace(urlRegex, url => {
    return `<a href="${url}" target="_blank" rel="noopener noreferrer" 
            style="color: #1a73e8; text-decoration: underline;">${url}</a>`;
  });
}

function loadMessages() {
  fetch('fetch_messages.php')
    .then(response => response.json())
    .then(messages => {
      const chatMessages = document.getElementById('chatMessages');
      const wasAtBottom = chatMessages.scrollHeight - chatMessages.clientHeight <= chatMessages.scrollTop + 10;
      
      if (chatMessages.dataset.messageCount == messages.length) return;
      chatMessages.dataset.messageCount = messages.length;
      
      chatMessages.innerHTML = '';
      
      messages.forEach(msg => {
        const senderType = msg.sender === 'You' ? 'you' : 'admin';
        const timestamp = new Date(msg.sent_at);
        const timeStr = timestamp.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        
        const messageDiv = document.createElement('div');
        messageDiv.classList.add('message-bubble', senderType);
        
        let contentHTML = '';
        
        if (msg.message) {
          if (senderType === 'admin') {
            contentHTML += `<div>${convertUrlsToLinks(escapeHtml(msg.message))}</div>`;
          } else {
            contentHTML += `<div>${escapeHtml(msg.message)}</div>`;
          }
        }
        
        if (msg.attachment_path) {
          const fileName = msg.attachment_path.split('/').pop();
          contentHTML += `
            <div class="file-attachment">
              <i class="fas fa-file"></i>
              <a href="${msg.attachment_path}" download="${fileName}">${fileName}</a>
            </div>
          `;
        }
        
        messageDiv.innerHTML = contentHTML;
        chatMessages.appendChild(messageDiv);
      });
      
      if (wasAtBottom) {
        chatMessages.scrollTop = chatMessages.scrollHeight;
      }
    })
    .catch(error => {
      console.error('Error loading messages:', error);
    });
}

function sendMessage() {
  const input = document.getElementById('chatInput');
  const text = input.value.trim();
  
  const linkPattern = /(https?:\/\/|www\.)\S+/i;
  if (linkPattern.test(text)) {
    showNotification('Sending links is not allowed', 'error');
    return;
  }

  if (selectedFile && selectedFile.size > 10 * 1024 * 1024) {
    showNotification('File size must be less than 10MB', 'error');
    return;
  }

  if (!text && !selectedFile) {
    return;
  }

  const formData = new FormData();
  formData.append('message', text);
  
  if (selectedFile) {
    formData.append('attachment', selectedFile);
  }

  input.value = '';
  removeFile();

  fetch('send_message.php', {
    method: 'POST',
    body: formData
  })
  .then(response => response.json())
  .then(data => {
    if (data.success) {
      loadMessages();
    } else {
      showNotification('Error: ' + (data.message || 'Unknown error'), 'error');
    }
  })
  .catch(error => {
    console.error('Error:', error);
    showNotification('Error sending message', 'error');
  });
}

let lastMessageCount = 0;
function checkForNewMessages() {
  fetch('fetch_messages.php')
    .then(response => response.json())
    .then(messages => {
      if (messages.length !== lastMessageCount) {
        lastMessageCount = messages.length;
        loadMessages();
      }
    })
    .catch(error => console.error('Polling error:', error));
}

loadMessages();
setInterval(checkForNewMessages, 3000);

document.getElementById('chatInput').addEventListener('keydown', function(e) {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault();
    sendMessage();
  }
});

// ====================
// Logout Functionality
// ====================

function logout() {
  fetch('logout.php')
    .then(() => {
      sessionStorage.clear();
      sessionStorage.setItem('logged_out', 'yes');
      history.pushState(null, null, 'index.html');
      window.location.href = "index.html";
    });
}

window.addEventListener('popstate', function() {
  if (sessionStorage.getItem('logged_out')) {
    history.go(1);
  }
});

// Load company name
fetch('companyname.php')
  .then(response => response.text())
  .then(name => {
    document.getElementById('companyNameBtn').querySelector('span').textContent = name || 'My Account';
  })
  .catch(() => {
    document.getElementById('companyNameBtn').querySelector('span').textContent = 'My Account';
  });

// Close modal when clicking outside
window.addEventListener('click', function(e) {
  const modal = document.getElementById('editAccountModal');
  if (e.target === modal) {
    closeModal();
  }
});

// Prevent modal close when clicking inside
document.querySelector('.modal-content').addEventListener('click', function(e) {
  e.stopPropagation();
});

// Back button handling
window.onload = function () {
  if (sessionStorage.getItem('logged_out') === 'yes') {
    sessionStorage.removeItem('logged_out');
    window.location.href = "index.html";
    return;
  }

  history.pushState(null, null, location.href);

  window.onpopstate = function () {
    location.href = "index.html";
  };
  
  // Fix for dropdown menu
  const dropdown = document.querySelector('.dropdown');
  const dropdownContent = document.querySelector('.dropdown-content');
  
  if (dropdown && dropdownContent) {
    let dropdownTimeout;
    
    dropdown.addEventListener('mouseenter', function() {
      clearTimeout(dropdownTimeout);
      dropdownContent.style.display = 'block';
    });
    
    dropdown.addEventListener('mouseleave', function() {
      dropdownTimeout = setTimeout(() => {
        dropdownContent.style.display = 'none';
      }, 300);
    });
    
    dropdownContent.addEventListener('mouseenter', function() {
      clearTimeout(dropdownTimeout);
    });
    
    dropdownContent.addEventListener('mouseleave', function() {
      dropdownTimeout = setTimeout(() => {
        dropdownContent.style.display = 'none';
      }, 100);
    });
  }
};
</script>
</body>
</html>