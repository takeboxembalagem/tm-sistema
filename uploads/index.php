<?php
// Pasta fora do document root — não deve ser servida pelo Nginx/Apache.
http_response_code(403);
exit('Forbidden');
