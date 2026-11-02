import { Navigate, Route, Routes } from "react-router-dom";
import Shell from "./components/Shell.jsx";
import Desk from "./pages/Desk.jsx";
import Login from "./pages/Login.jsx";
import Rooms from "./pages/Rooms.jsx";
import Stays from "./pages/Stays.jsx";

export default function App() {
  return (
    <Routes>
      <Route path="/login" element={<Login />} />
      <Route element={<Shell />}>
        <Route index element={<Rooms />} />
        <Route path="stays" element={<Stays />} />
        <Route path="desk" element={<Desk />} />
      </Route>
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  );
}
