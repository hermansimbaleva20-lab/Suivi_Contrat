import React from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import 'bootstrap/dist/css/bootstrap.min.css';
import 'bootstrap-icons/font/bootstrap-icons.css';
import 'leaflet/dist/leaflet.css';
import './styles/prise-ui.css';
import './styles/prise-v19.css';
import './styles/prise-react.css';
import './styles/prise-design.css';
import './styles/tailwind.css';
import App from './App.jsx';
import { AuthProvider } from './context/AuthContext.jsx';

createRoot(document.getElementById('root')).render(
  <BrowserRouter>
    <AuthProvider>
      <App />
    </AuthProvider>
  </BrowserRouter>,
);
