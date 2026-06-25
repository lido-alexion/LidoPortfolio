import React from 'react';
import { Navigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

export default function AdminRoute({ children }) {
    const { user, loading } = useAuth();

    if (loading) {
        return (
            <div className="text-center py-5">
                <div className="spinner-border text-info" role="status" />
            </div>
        );
    }

    if (!user?.is_admin) {
        return <Navigate to="/settings" replace />;
    }

    return children;
}
