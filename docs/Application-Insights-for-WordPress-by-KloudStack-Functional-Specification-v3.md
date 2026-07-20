# Application Insights for WordPress (by KloudStack)

## Functional Specification & Implementation Roadmap

**Version:** 3.0 (Final Draft)\
**Date:** July 2026

------------------------------------------------------------------------

# Executive Summary

This document defines the functional roadmap for evolving the current
KloudStack Application Insights MU-plugin into a production-ready
open-source WordPress plugin.

The project will maintain:

-   One codebase
-   One GitHub repository
-   One release process
-   One plugin for both the public community and KloudStack managed
    WordPress stacks

The plugin's responsibility is to collect telemetry and integrate
WordPress with Azure Monitor and Application Insights. AI-driven
diagnostics, operational intelligence and automated remediation remain
responsibilities of the KloudStack platform.

------------------------------------------------------------------------

# Product Vision

Application Insights for WordPress (by KloudStack) aims to become the
reference Azure-native observability plugin for WordPress.

The project exists to:

-   Modernise Application Insights support for WordPress.
-   Provide first-class Azure integration.
-   Support developers, freelancers and agencies building on Azure.
-   Form the telemetry foundation for the KloudStack AI-native cloud
    platform for self-managing application stacks.

------------------------------------------------------------------------

# Target Audience

Primary users include:

-   Freelance WordPress developers
-   Independent consultants
-   Small digital agencies
-   Azure solution providers
-   Managed Service Providers (MSPs)
-   Organisations hosting WordPress on Microsoft Azure

------------------------------------------------------------------------

# Architecture Updates

## Current State

-   Embedded MU-plugin within the KloudStack WordPress image.
-   Versioned alongside the image.
-   Automatically deployed to all KloudStack WordPress stacks.

## Future State

-   Dedicated GitHub repository.
-   Single shared codebase.
-   Standard WordPress plugin for public distribution.
-   Lightweight MU-loader retained for KloudStack managed deployments.
-   WordPress image rebuilds download approved tagged releases from
    GitHub.
-   Semantic versioning maintained across releases.

------------------------------------------------------------------------

# Functional Roadmap

## Administration

-   Settings page
-   Diagnostics dashboard
-   Azure connection status
-   Telemetry health checks
-   Plugin self-test
-   Version information
-   Automatic updates
-   WordPress.org compatible packaging
-   Multisite support

## Telemetry

### Existing

-   Browser telemetry
-   Request telemetry
-   Exception telemetry

### Planned

-   W3C Trace Context
-   operation_Id
-   operation_ParentId
-   Dependency telemetry
-   Trace telemetry
-   Custom metrics

### Dependencies

-   MySQL
-   Redis
-   Azure Blob Storage
-   HTTP / cURL
-   SMTP
-   REST API

### Metrics

-   PHP memory
-   Peak memory
-   Query count
-   Cache hit/miss
-   Redis activity
-   External HTTP calls

------------------------------------------------------------------------

# WordPress Compatibility

Support should be automatic without requiring custom configuration.

-   WordPress Core
-   Elementor
-   W3 Total Cache
-   WooCommerce
-   Wordfence
-   WP Mail SMTP

------------------------------------------------------------------------

# Azure Environment Support

Supported environments:

-   Azure App Service
-   Azure Kubernetes Service (AKS)
-   Azure Container Apps
-   Azure Virtual Machines
-   Local development
-   Generic Linux hosting (basic functionality)

------------------------------------------------------------------------

# Compatibility Matrix

  Category        Integration             Status
  --------------- ----------------------- --------------
  WordPress       Core                    ✅ Supported
  Page Builder    Elementor               ✅ Supported
  Cache           W3 Total Cache          ✅ Supported
  Commerce        WooCommerce             ✅ Supported
  Security        Wordfence               ✅ Supported
  Email           WP Mail SMTP            ✅ Supported
  SEO             Yoast SEO               🟡 Planned
  Forms           Gravity Forms           🟡 Planned
  Forms           Contact Form 7          🟡 Planned
  Custom Fields   ACF                     🟡 Planned
  Backup          UpdraftPlus             🟡 Planned
  Search          Relevanssi              🔬 Research
  Multilingual    WPML / Polylang         🔬 Research
  Azure           Blob Storage            ✅ Supported
  Azure           Azure Cache for Redis   ✅ Supported
  Azure           Front Door              ✅ Supported
  Azure           Key Vault               🟡 Planned
  Azure           App Service             ✅ Supported
  Azure           AKS                     🟡 Planned
  Azure           Container Apps          🟡 Planned
  Monitoring      Azure Monitor           ✅ Supported
  Monitoring      Application Insights    ✅ Supported
  Monitoring      Log Analytics           ✅ Supported

Legend:

-   ✅ Supported
-   🟡 Planned
-   🔬 Research

------------------------------------------------------------------------

# Supported Telemetry Matrix

  --------------------------------------------------------------------------------
  Integration     Requests      Traces     Dependencies    Metrics     Exceptions
  ------------- ------------ ------------ -------------- ------------ ------------
  WordPress          ✅           🟡            🟡            ✅           ✅
  Core                                                                

  Elementor          ✅           🟡            🟡            ✅           ✅

  W3 Total           ✅           🟡            ✅            ✅           ✅
  Cache                                                               

  WooCommerce        ✅           🟡            ✅            ✅           ✅

  Wordfence          ✅           🟡            🟡            ✅           ✅

  Azure Blob         🟡           🟡            ✅            ✅           ✅
  Storage                                                             

  Azure Cache        🟡           🟡            ✅            ✅           ✅
  for Redis                                                           

  HTTP/cURL          🟡           🟡            ✅            🟡           ✅
  --------------------------------------------------------------------------------

------------------------------------------------------------------------

# Platform Responsibilities

## Plugin

-   Collect telemetry
-   Integrate with Azure Monitor
-   Surface diagnostics
-   Integrate with common WordPress plugins
-   Remain lightweight and open source

## KloudStack Platform

-   AI-powered telemetry analysis
-   Automated remediation
-   Fleet management
-   Performance recommendations
-   Self-managing application stacks

------------------------------------------------------------------------

# Success Criteria

The project will be considered successful when it:

-   Is recognised as the reference Application Insights plugin for
    WordPress on Azure.
-   Is used across every KloudStack WordPress deployment.
-   Is maintained as a trusted open-source project.
-   Supports the most common Azure-hosted WordPress workloads used by
    freelancers, agencies and solution providers.
-   Provides a stable telemetry foundation for the KloudStack AI-native
    cloud platform.

------------------------------------------------------------------------

# Conclusion

Application Insights for WordPress (by KloudStack) is intended to solve
a genuine gap in the Azure WordPress ecosystem by providing a modern,
actively maintained observability plugin built specifically for
Azure-hosted WordPress.

The plugin should remain focused on telemetry, diagnostics and Azure
integration, while the KloudStack platform builds on that telemetry to
deliver AI-powered operational insights and automation. By maintaining a
single codebase shared between the public project and KloudStack managed
deployments, the project benefits from continuous real-world validation,
simplified maintenance and a stronger open-source community.
