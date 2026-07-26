اولویت‌بندی نهایی اصلاح‌شده (بدون Migration)
اولویت A — پایه و زیرساخت

تکمیل و اصلاح کامل Models (SoftDeletes، Casts، Relations صحیح، fillable/guarded، نام جداول یکدست با schema فعلی)
Seed اولیه نقش‌ها (public + نقش‌های پایه Phase 2 مثل admin, staff, ...) و یک کاربر تست
Rate Limiting پایه + Middlewareهای Auth و Role/Permission
Logging متمرکز (AuditLog) + Error Handling یکدست (ApiResponser)

اولویت B — Document Lifecycle (با تگ‌گذاری Phase 2 از اول)
5. Document Controller + Service + Repository

Upload / Create
Update + Versioning
Publish / Archive / Soft Delete
Attach/Detach Role + Department + Permission
فیلتر دسترسی: کاربران public فقط اسناد دارای تگ عمومی را می‌بینند

اولویت C — Chat & Feedback
6. ChatSession + ChatMessage CRUD
7. Feedback System
8. Endpointهای frontend
   اولویت D — Integration با Python RAG
9. Proxy/Orchestrator + Streaming (SSE) + Redis state + MySQL history
10. Concurrency پایه
    اولویت E — Hardening
11. Rate Limiting واقعی + Validation سخت‌گیرانه + Feature Tests
