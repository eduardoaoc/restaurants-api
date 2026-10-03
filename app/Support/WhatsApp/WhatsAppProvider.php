<?php

namespace App\Support\WhatsApp;

/**
 * The only boundary between the delivery domain and WhatsApp (CARTA
 * 9.1E). CloseRestaurantDayAction never touches it; only the queued
 * SendDayCloseWhatsApp job does. Bound to MetaWhatsAppCloudApi in
 * AppServiceProvider; tests use Http::fake() against the real
 * implementation or swap the binding.
 */
interface WhatsAppProvider
{
    public function sendTemplate(WhatsAppTemplateMessage $message): WhatsAppSendResult;
}
